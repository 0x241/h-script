<?php

declare(strict_types=1);

/** CI-only policy; scanner payloads and matched secret text never enter evidence. */
final class SupplyChainPolicy
{
    public function __construct(private array $policy)
    {
        if (($policy['format'] ?? null) !== 1 || ($policy['blocking_severities'] ?? null) !== ['HIGH', 'CRITICAL', 'UNKNOWN']
            || ($policy['maximum_report_age_hours'] ?? null) !== 24 || ($policy['maximum_exception_days'] ?? null) !== 30
            || !is_array($policy['exceptions'] ?? null)) throw new RuntimeException('Invalid policy');
        foreach ($policy['exceptions'] as $exception) {
            foreach (['scanner', 'scope', 'package', 'id', 'owner', 'approved_by', 'ticket', 'reason', 'reachability', 'exploitability', 'created_at', 'expires_at'] as $key)
                if (!is_string($exception[$key] ?? null) || trim($exception[$key]) === '' || str_contains($exception[$key], '*'))
                    throw new RuntimeException('Incomplete or wildcard exception');
            $start = strtotime($exception['created_at']);
            $end = strtotime($exception['expires_at']);
            if ($start === false || $end === false || $end <= $start || $end - $start > 30 * 86400)
                throw new RuntimeException('Invalid exception interval');
        }
    }

    public function normalize(string $scanner, string $scope, array $raw, int $exitCode): array
    {
        if (!preg_match('/^(production|toolchain|source|shared|image-amd64|image-arm64)$/D', $scope)) throw new RuntimeException('Invalid scope');
        $findings = [];
        if (isset($raw['error']) || isset($raw['errors'])) throw new RuntimeException('Scanner failed');
        if ($scanner === 'composer') {
            if (!empty($raw['malware']) || !empty($raw['ignored-advisories'])) throw new RuntimeException('Unsupported or ignored dependency policy findings');
            if (!is_array($raw['advisories'] ?? null) || !is_array($raw['abandoned'] ?? null) || $exitCode > 3)
                throw new RuntimeException('Invalid Composer report');
            foreach ($raw['advisories'] as $package => $advisories) {
                if (!is_array($advisories)) throw new RuntimeException('Invalid advisories');
                foreach ($advisories as $entry)
                    $findings[] = $this->finding($entry['advisoryId'] ?? $entry['cve'] ?? null, $package, $entry['severity'] ?? 'UNKNOWN');
            }
            foreach ($raw['abandoned'] as $package => $replacement)
                $findings[] = $this->finding('abandoned', $package, 'HIGH');
        } elseif ($scanner === 'npm') {
            if (($raw['auditReportVersion'] ?? null) !== 2 || !is_array($raw['vulnerabilities'] ?? null)
                || !is_array($raw['metadata']['vulnerabilities'] ?? null) || $exitCode > 1)
                throw new RuntimeException('Invalid npm report');
            foreach ($raw['vulnerabilities'] as $package => $entry) {
                if (!is_array($entry['via'] ?? null)) throw new RuntimeException('Invalid npm advisory');
                $ids = [];
                foreach ($entry['via'] as $via)
                    if (is_array($via)) $ids[] = 'npm-' . (string)($via['source'] ?? 'unknown');
                // Meta-vulnerabilities are still blocked, not lost when via contains names only.
                foreach ($ids ?: ['npm-meta-' . $package] as $id)
                    $findings[] = $this->finding($id, $package, $entry['severity'] ?? 'UNKNOWN');
            }
        } elseif ($scanner === 'trivy') {
            if (($raw['SchemaVersion'] ?? null) !== 2 || !is_string($raw['ArtifactName'] ?? null)
                || (isset($raw['Results']) && !is_array($raw['Results'])) || $exitCode !== 0)
                throw new RuntimeException('Invalid Trivy report');
            foreach ($raw['Results'] ?? [] as $result) {
                foreach ($result['Vulnerabilities'] ?? [] as $entry)
                    $findings[] = $this->finding($entry['VulnerabilityID'] ?? null, $entry['PkgName'] ?? null, $entry['Severity'] ?? 'UNKNOWN');
                foreach ($result['Secrets'] ?? [] as $entry)
                    $findings[] = $this->finding($entry['RuleID'] ?? null, 'secret', 'CRITICAL', 'secret');
            }
        } else throw new RuntimeException('Unknown scanner');
        if ($exitCode !== 0 && !$findings) throw new RuntimeException('Scanner failed without findings');
        return ['format' => 1, 'scanner' => $scanner, 'scope' => $scope, 'checked_at' => gmdate('c'), 'findings' => $findings];
    }

    public function evaluate(array $report, ?int $now = null, bool $requireFresh = true): array
    {
        $now ??= time();
        $checked = strtotime((string)($report['checked_at'] ?? ''));
        if (($report['format'] ?? null) !== 1 || !in_array($report['scanner'] ?? '', ['composer', 'npm', 'trivy'], true)
            || !preg_match('/^(production|toolchain|source|shared|image-amd64|image-arm64)$/D', (string)($report['scope'] ?? ''))
            || !is_array($report['findings'] ?? null) || $checked === false || $checked > $now + 60 || ($requireFresh && $now - $checked > 86400))
            throw new RuntimeException('Invalid or expired scan evidence');
        $blocked = 0;
        foreach ($report['findings'] as &$finding) {
            if (!is_array($finding) || !in_array($finding['kind'] ?? '', ['vulnerability', 'secret'], true)) throw new RuntimeException('Malformed finding');
            $validated = $this->finding($finding['id'] ?? null, $finding['package'] ?? null, $finding['severity'] ?? null, $finding['kind']);
            if ($validated['severity'] !== ($finding['severity'] ?? null)) throw new RuntimeException('Invalid normalized severity');
            $finding['decision'] = 'track';
            if (!in_array($finding['severity'], $this->policy['blocking_severities'], true)) continue;
            $finding['decision'] = 'block';
            foreach ($this->policy['exceptions'] as $exception) {
                if ($finding['kind'] === 'secret') break;
                if ($exception['scanner'] === $report['scanner'] && $exception['scope'] === $report['scope']
                    && $exception['id'] === $finding['id'] && $exception['package'] === $finding['package']
                    && strtotime($exception['created_at']) <= $now && strtotime($exception['expires_at']) > $now) {
                    $finding['decision'] = 'exception';
                    break;
                }
            }
            if ($finding['decision'] === 'block') $blocked++;
        }
        unset($finding);
        $report['blocked'] = $blocked;
        return $report;
    }

    private function finding(mixed $id, mixed $package, mixed $severity, string $kind = 'vulnerability'): array
    {
        foreach ([$id, $package] as $value)
            if (!is_string($value) || !preg_match('~^[A-Za-z0-9@_./:+-]{1,200}$~D', $value)) throw new RuntimeException('Invalid finding identity');
        $severity = strtoupper((string)$severity);
        if ($severity === 'MODERATE') $severity = 'MEDIUM';
        if (!in_array($severity, ['LOW', 'MEDIUM', 'HIGH', 'CRITICAL', 'UNKNOWN'], true)) $severity = 'UNKNOWN';
        return ['id' => $id, 'package' => $package, 'severity' => $severity, 'kind' => $kind];
    }
}
