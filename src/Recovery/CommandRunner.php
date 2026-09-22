<?php

namespace HScript\Recovery;

use RuntimeException;

final class CommandRunner
{
	private const MAX_OUTPUT_BYTES = 1048576;

	public function run(array $command, ?string $workingDirectory = null, array $environment = array(), string $stdin = '', ?float $timeoutSeconds = null): CommandResult
	{
		if ($timeoutSeconds !== null && (!is_finite($timeoutSeconds) || $timeoutSeconds <= 0))
			throw new RuntimeException('Command timeout is invalid');
		if (!$command || !is_string($command[0]) || !str_starts_with($command[0], '/'))
			throw new RuntimeException('Command executable must be an absolute path');
		foreach ($command as $argument)
			if (!is_string($argument) || str_contains($argument, "\0"))
				throw new RuntimeException('Command argument is invalid');

		$processEnvironment = getenv();
		if (!is_array($processEnvironment))
			$processEnvironment = array();
		foreach ($environment as $name => $value)
		{
			if (!is_string($name) || !preg_match('/^[A-Z][A-Z0-9_]{0,63}$/', $name) || !is_string($value))
				throw new RuntimeException('Command environment is invalid');
			$processEnvironment[$name] = $value;
		}

		$pipes = array();
		$process = proc_open($command, array(
			0 => array('pipe', 'r'),
			1 => array('pipe', 'w'),
			2 => array('pipe', 'w'),
		), $pipes, $workingDirectory, $processEnvironment, array('bypass_shell' => true));
		if (!is_resource($process))
			throw new RuntimeException('Command could not be started');

		foreach ($pipes as $pipe)
			stream_set_blocking($pipe, false);
		$pending = $stdin;
		$stdinClosed = false;
		$stdout = '';
		$stderr = '';
		$lastExitCode = null;
		$deadline = $timeoutSeconds === null ? null : hrtime(true) + $timeoutSeconds * 1000000000;
		while (true)
		{
			if ($deadline !== null && hrtime(true) >= $deadline)
			{
				// Close inherited pipes too: a notifier child must not hold the caller open.
				proc_terminate($process, 9);
				foreach ($pipes as $pipe)
					if (is_resource($pipe)) fclose($pipe);
				proc_close($process);
				throw new RuntimeException('Command timed out');
			}
			$status = proc_get_status($process);
			if (!$status['running'] && is_int($status['exitcode']) && $status['exitcode'] >= 0)
				$lastExitCode = $status['exitcode'];
			$read = array();
			if (!feof($pipes[1])) $read[] = $pipes[1];
			if (!feof($pipes[2])) $read[] = $pipes[2];
			$write = !$stdinClosed && $pending !== '' ? array($pipes[0]) : array();
			if (!$stdinClosed && $pending === '')
			{
				fclose($pipes[0]);
				$stdinClosed = true;
			}
			if ($read || $write)
			{
				$except = null;
				$selected = stream_select($read, $write, $except, 1);
				if ($selected === false)
				{
					proc_terminate($process);
					throw new RuntimeException('Command stream failed');
				}
				foreach ($read as $stream)
				{
					$chunk = fread($stream, 65536);
					if (!is_string($chunk) || $chunk === '') continue;
					if ($stream === $pipes[1])
						$stdout = substr($stdout . $chunk, 0, self::MAX_OUTPUT_BYTES);
					else
						$stderr = substr($stderr . $chunk, 0, self::MAX_OUTPUT_BYTES);
				}
				if ($write)
				{
					$written = fwrite($pipes[0], $pending);
					if ($written === false)
					{
						proc_terminate($process);
						throw new RuntimeException('Command input failed');
					}
					$pending = (string)substr($pending, $written);
				}
			}
			if (!$status['running'] && feof($pipes[1]) && feof($pipes[2]))
				break;
		}
		foreach ($pipes as $pipe)
			if (is_resource($pipe)) fclose($pipe);
		$exitCode = proc_close($process);
		if ($exitCode < 0 && $lastExitCode !== null) $exitCode = $lastExitCode;
		return new CommandResult($exitCode, $stdout, $stderr);
	}
}
