<?php

use HScript\Database\Connection;
use HScript\Mail\Mailer;
use HScript\Queue\JobQueue;

require dirname(__DIR__) . '/vendor/autoload.php';

function queueAssert(bool $condition, string $message): void
{
	if (!$condition)
		throw new RuntimeException($message);
}

final class JobQueueFakeConnection extends Connection
{
	public array $jobs = array();
	private int $nextId = 1;

	public function insert($table, $values, $fields = '', $asReplace = false)
	{
		queueAssert($table === 'Jobs', 'Unexpected insert table');
		$id = $this->nextId++;
		$values['jID'] = $id;
		$this->jobs[$id] = $values;
		return $id;
	}

	public function select($table, $fields = '*', $filter = '', $values = null, $order = '', $limit = '', $group = '')
	{
		return array(
			'table' => $table,
			'fields' => $fields,
			'filter' => $filter,
			'values' => $values ?? array(),
			'order' => $order,
			'limit' => $limit,
		);
	}

	public function fetch1Row($query)
	{
		$rows = $this->selectedRows($query);
		return $rows ? reset($rows) : array();
	}

	public function fetchRows($query, $singleField = false)
	{
		$rows = $this->selectedRows($query);
		if ($singleField === 1)
			return array_map(static fn(array $row) => reset($row), $rows);
		if ($singleField)
			return array_column($rows, $singleField);
		return array_values($rows);
	}

	public function update($table, $values, $fields = '', $filter = '', $parameters = null)
	{
		queueAssert($table === 'Jobs', 'Unexpected update table');
		$updated = 0;
		foreach ($this->jobs as &$job)
		{
			if (!$this->matches($job, $filter, $parameters ?? array()))
				continue;
			foreach ($values as $field => $value)
			{
				if ($field === 'jAttempts=' && $value === 'jAttempts+1')
					$job['jAttempts']++;
				else
					$job[$field] = $value;
			}
			$updated++;
		}
		unset($job);
		return $updated;
	}

	public function delete($table, $filter = '', $parameters = null, $order = '', $limit = '')
	{
		queueAssert($table === 'Jobs', 'Unexpected delete table');
		$deleted = 0;
		foreach ($this->jobs as $id => $job)
		{
			if (!$this->matches($job, $filter, $parameters ?? array()))
				continue;
			unset($this->jobs[$id]);
			$deleted++;
		}
		return $deleted;
	}

	public function setJob(int $jobId, array $values): void
	{
		$this->jobs[$jobId] = array_replace($this->jobs[$jobId], $values);
	}

	private function selectedRows(array $query): array
	{
		queueAssert($query['table'] === 'Jobs', 'Unexpected select table');
		$rows = array_filter(
			$this->jobs,
			fn(array $job): bool => $this->matches($job, $query['filter'], $query['values'])
		);
		if ($query['order'])
		{
			$orderFields = array_map('trim', explode(',', $query['order']));
			uasort($rows, static function (array $left, array $right) use ($orderFields): int
			{
				foreach ($orderFields as $field)
				if (($comparison = $left[$field] <=> $right[$field]) !== 0)
					return $comparison;
				return 0;
			});
		}
		if ($query['limit'])
			$rows = array_slice($rows, 0, (int)$query['limit'], true);
		if ($query['fields'] !== '*')
		{
			$selectedFields = array_map('trim', explode(',', $query['fields']));
			$rows = array_map(
				static fn(array $row): array => array_intersect_key($row, array_flip($selectedFields)),
				$rows
			);
		}
		return $rows;
	}

	private function matches(array $job, string $filter, array $values): bool
	{
		return match ($filter)
		{
			'' => true,
			'jID=?d' => $job['jID'] === (int)$values[0],
			'jState=?d and jAttempts<jMaxAttempts' =>
				$job['jState'] === (int)$values[0] && $job['jAttempts'] < $job['jMaxAttempts'],
			'jID=?d and jState=?d and jAttempts<jMaxAttempts' =>
				$job['jID'] === (int)$values[0] &&
				$job['jState'] === (int)$values[1] &&
				$job['jAttempts'] < $job['jMaxAttempts'],
			'jID=?d and jState=?d' =>
				$job['jID'] === (int)$values[0] && $job['jState'] === (int)$values[1],
			'jState=?d and jAttempts<jMaxAttempts and jPTS<=?' =>
				$job['jState'] === (int)$values[0] &&
				$job['jAttempts'] < $job['jMaxAttempts'] &&
				$job['jPTS'] <= (int)$values[1],
			'jID ?i and jState=?d and jAttempts<jMaxAttempts' =>
				in_array($job['jID'], $values[0], true) &&
				$job['jState'] === (int)$values[1] &&
				$job['jAttempts'] < $job['jMaxAttempts'],
			'jState=?d and jPTS<?' =>
				$job['jState'] === (int)$values[0] && $job['jPTS'] < (int)$values[1],
			'jState ?i and jDTS>0 and jDTS<?' =>
				in_array($job['jState'], $values[0], true) &&
				$job['jDTS'] > 0 &&
				$job['jDTS'] < (int)$values[1],
			default => throw new RuntimeException('Unexpected filter: ' . $filter),
		};
	}
}

ini_set('error_log', '/dev/null');

$db = new JobQueueFakeConnection();
$jobQueue = new JobQueue($db);

queueAssert(
	Mailer::send('queue@example.test', 'Queued mail', '<p>Message</p>'),
	'Mailer did not enqueue a valid email'
);
$emailId = array_key_first($db->jobs);
queueAssert($db->jobs[$emailId]['jType'] === 'email', 'Mailer created the wrong job type');
queueAssert($db->jobs[$emailId]['jState'] === JobQueue::STATE_PENDING, 'Email is not pending');

$jobQueue->registerHandler('email', static fn(array $payload): array => array(
	'delivered' => $payload['to'] === 'queue@example.test',
));
queueAssert($jobQueue->processBatch(10) === 1, 'Email batch size is wrong');
queueAssert($db->jobs[$emailId]['jState'] === JobQueue::STATE_DONE, 'Email was not completed');
queueAssert(
	JobQueue::decodePayload($db->jobs[$emailId]['jPayload'])['result']['delivered'] === true,
	'Email handler result was not stored'
);

$flakyRuns = 0;
$jobQueue->registerHandler('flaky', static function () use (&$flakyRuns): array
{
	$flakyRuns++;
	if ($flakyRuns === 1)
		throw new RuntimeException('Temporary failure');
	return array('recovered' => true);
});
$flakyId = $jobQueue->dispatch('flaky', array('test' => true));
queueAssert($jobQueue->processNext(), 'Flaky job was not processed');
queueAssert($db->jobs[$flakyId]['jState'] === JobQueue::STATE_FAILED, 'Failed job has wrong state');
queueAssert($db->jobs[$flakyId]['jAttempts'] === 1, 'Failed attempt was not counted');
$jobQueue->retry($flakyId);
queueAssert($db->jobs[$flakyId]['jState'] === JobQueue::STATE_PENDING, 'Failed job was not retried');
queueAssert($jobQueue->processNext(), 'Retried job was not processed');
queueAssert($db->jobs[$flakyId]['jState'] === JobQueue::STATE_DONE, 'Retried job did not recover');
queueAssert($db->jobs[$flakyId]['jAttempts'] === 2, 'Retry attempt count is wrong');

$jobQueue->registerHandler('fatal', static function (): void
{
	throw new RuntimeException('Permanent failure');
});
$fatalId = $jobQueue->dispatch('fatal', array(), 3);
for ($attempt = 1; $attempt <= 3; $attempt++)
{
	queueAssert($jobQueue->processNext(), "Fatal attempt $attempt was not processed");
	if ($attempt < 3)
		$jobQueue->retry($fatalId);
}
$jobQueue->retry($fatalId);
queueAssert($db->jobs[$fatalId]['jState'] === JobQueue::STATE_FAILED, 'Attempt limit was bypassed');
queueAssert($db->jobs[$fatalId]['jAttempts'] === 3, 'Maximum attempt count is not three');
queueAssert(!$jobQueue->processNext(), 'Exhausted job returned to the queue');

$staleId = $jobQueue->dispatch('stale', array());
$db->setJob($staleId, array(
	'jState' => JobQueue::STATE_PROCESSING,
	'jAttempts' => 1,
	'jPTS' => timeToStamp(time() - 700),
));
queueAssert($jobQueue->recoverStale(600) === 1, 'Stale processing job was not recovered');
queueAssert($db->jobs[$staleId]['jState'] === JobQueue::STATE_FAILED, 'Stale job has wrong state');
queueAssert($jobQueue->retryFailed(10, 0) === 1, 'Automatic retry did not requeue a failed job');
queueAssert($db->jobs[$staleId]['jState'] === JobQueue::STATE_PENDING, 'Automatic retry state is wrong');

$db->setJob($emailId, array('jDTS' => timeToStamp(time() - (31 * HS2_UNIX_DAY))));
queueAssert($jobQueue->cleanup(30) === 1, 'Old completed job was not deleted');
queueAssert(!isset($db->jobs[$emailId]), 'Cleanup left the old completed job behind');

echo "Job queue component tests passed.\n";
