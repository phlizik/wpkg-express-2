<?php
/**
 * This file is the model file of the plugin.
 * Get information of job queue
 *
 * CakeTheme: Set theme for application.
 * @copyright Copyright 2016-2019, Andrey Klimov.
 * @license https://opensource.org/licenses/mit-license.php MIT License
 * @package app.Model
 */

App::uses('QueuedTask', 'Model');
App::uses('ClassRegistry', 'Utility');

/**
 * ExtendQueuedTask for CakeTheme.
 *
 * @package plugin.Model
 */
class ExtendQueuedTask extends QueuedTask {

/**
 * Name of the model.
 *
 * @var string
 */
	public $name = 'ExtendQueuedTask';

/**
 * Custom database table name, or null/false if no table association is desired.
 *
 * @var string
 */
	public $useTable = 'queued_tasks';

/**
 * Constructor. Binds the model's database table to the object.
 *
 * @param bool|int|string|array $id Set this ID for this model on startup,
 * can also be an array of options, see above.
 * @param string $table Name of database table to use.
 * @param string $ds DataSource connection name.
 */
	public function __construct($id = false, $table = null, $ds = null) {
		parent::__construct($id, $table, $ds);

		if ($this->isDataSourceMysql()) {
			$this->virtualFields['age'] = 'IFNULL(TIMESTAMPDIFF(SECOND, NOW(),notbefore), 0)';
		} elseif ($this->isDataSourcePostgres()) {
			$this->virtualFields['age'] = 'COALESCE(EXTRACT(EPOCH FROM (notbefore::timestamp - NOW())), 0)';
		} else {
			$this->virtualFields['age'] = '0';
		}
	}

/**
 * Get length of job queue for not started task
 *
 * @param string $jobType Type of job.
 * @return int Length of job queue
 */
	public function getLengthQueue($jobType = null) {
		$jobType = (string)$jobType;
		$queueLength = 0;
		$pendingStats = $this->getPendingStats();
		if (empty($pendingStats)) {
			return $queueLength;
		}

		foreach ($pendingStats as $pendingStatsItem) {
			if ((!empty($jobType) && (strcmp($pendingStatsItem[$this->alias]['jobtype'], $jobType) !== 0)) ||
				($pendingStatsItem[$this->alias]['status'] !== 'NOT_STARTED')) {
				continue;
			}

			$queueLength++;
		}

		return $queueLength;
	}

/**
 * Return statistics about job still in the Database.
 *
 * @param string $jobType Type of job.
 * @param bool $includeFinished If True, include finished task in result.
 * @param int $timestamp Timestamp for retrieving statistics.
 * @return array|bool Return statistics, or False on failure.
 */
	public function getPendingJob($jobType = null, $includeFinished = false, $timestamp = null) {
		if (empty($jobType)) {
			return false;
		}

		if (empty($timestamp) || (!is_int($timestamp) || !ctype_digit($timestamp))) {
			$timestamp = strtotime('-15 minute');
		}

		$fetchedTime = date('Y-m-d H:i:s', $timestamp);
		$ds = $this->getDataSource();
		$table = $ds->fullTableName($this);
		$alias = $this->alias;
		$fields = [
			$alias . '.' . $this->primaryKey,
			$alias . '.jobtype',
			$alias . '.created',
			$alias . '.status',
			$alias . '.age',
			$alias . '.fetched',
			$alias . '.progress',
			$alias . '.completed',
			$alias . '.reference',
			$alias . '.failed',
			$alias . '.failure_message'
		];
		$conditions = [
			'OR' => [
				[ // NOT_READY
					$alias . '.notbefore > NOW()',
				],
				[ // NOT_STARTED
					$alias . '.fetched' => null,
				],
				[ // IN_PROGRESS
					'AND' => [
						$alias . '.fetched NOT' => null,
						$alias . '.fetched >' => $fetchedTime,
						$alias . '.completed' => null,
						$alias . '.failed' => 0,
					],
				]
			],
			$alias . '.jobtype' => $jobType,
		];
		if ($includeFinished) {
			$conditions['OR'][] = [ // FAILED
				'AND' => [
					$alias . '.fetched NOT' => null,
					$alias . '.fetched >' => $fetchedTime,
					$alias . '.completed' => null,
					$alias . '.failed >' => 0,
				],
			];
			$conditions['OR'][] = [ // COMPLETED
				'AND' => [
					$alias . '.fetched NOT' => null,
					$alias . '.fetched >' => $fetchedTime,
					$alias . '.completed NOT' => null,
				],
			];
		}
		$fields = $ds->fields($this, $alias, $fields, true);
		$queryData = compact('fields', 'table', 'alias', 'conditions');

		$intDataType = $this->getTypeIntegerByDS();
		$aliasSubQuery = 'Sub';
		$fieldPrefix = $alias;
		$primaryKeyAlias = $fieldPrefix . '.' . $this->primaryKey;
		if ($this->isDataSourcePostgres()) {
			$fieldPrefix = $aliasSubQuery . '.' . $alias;
			$primaryKeyAlias = $fieldPrefix . '__' . $this->primaryKey;
		}

		$order = [
				'(CASE WHEN ' . $fieldPrefix . '__status = \'NOT_READY\' THEN 1 ' .
				'WHEN ' . $fieldPrefix . '__status = \'NOT_STARTED\' THEN 2 ' .
				'WHEN ' . $fieldPrefix . '__status = \'IN_PROGRESS\' THEN 3 ' .
				'WHEN ' . $fieldPrefix . '__status = \'FAILED\' THEN 4 ' .
				'WHEN ' . $fieldPrefix . '__status = \'COMPLETED\' THEN 5 END)' => 'ASC',
			'CAST(' . $fieldPrefix . '__age AS ' . $intDataType . ')' => 'DESC',
			'(CASE WHEN ' . $fieldPrefix . '__status IN(\'NOT_READY\', \'NOT_STARTED\', \'IN_PROGRESS\') THEN ' .
				$primaryKeyAlias . ' END)' => 'ASC',
			'(CASE WHEN ' . $fieldPrefix . '__status IN(\'FAILED\', \'COMPLETED\') THEN ' .
				$primaryKeyAlias . ' END)' => 'DESC',
		];
		$limit = 1;

		if ($this->isDataSourcePostgres()) {
			$subQuery = $ds->buildStatement($queryData, $this);
			$queryData = [
			'fields' => ['*'],
			'table' => '(' . $subQuery . ')',
			'order' => $order,
			'alias' => $aliasSubQuery,
			'limit' => $limit
			];
		} else {
			$queryData += compact('order', 'limit');
		}

		$query = $ds->buildStatement($queryData, $this);
		$data = $this->query($query);
		if (empty($data)) {
			return $data;
		}

		return array_shift($data);
	}

/**
 * Update massage of job
 *
 * @param int $id ID of job
 * @param string $message Message for update
 * @return bool Success
 */
	public function updateMessage($id = null, $message = null) {
		if (empty($id) || !$this->exists($id)) {
			return false;
		}

		$message = (string)$message;
		if (empty($message)) {
			$message = null;
		}

		$this->id = (int)$id;

		return (bool)$this->saveField('failure_message', $message);
	}

/**
 * Update progress of job
 *
 * @param int $id ID of job
 * @param int &$step Current step of job
 * @param int $maxStep Maximum steps of job
 * @return bool Success
 */
	public function updateTaskProgress($id, &$step, $maxStep = 0) {
		if (empty($id) || !$this->exists($id) ||
			($maxStep < 1) || ($maxStep < $step)) {
			return false;
		}

		$step++;
		$progress = $step / $maxStep;

		return $this->updateProgress($id, $progress);
	}

/**
 * Update error massage of job
 *
 * @param int $id ID of job
 * @param string $errorMessage Error message for update
 * @param bool $keepExistingMessage If True, keep existing
 *  error message
 * @return bool Success
 */
	public function updateTaskErrorMessage($id = null, $errorMessage = null, $keepExistingMessage = false) {
		if (empty($id) || !$this->exists($id) || empty($errorMessage)) {
			return false;
		}

		if ($keepExistingMessage) {
			$this->id = $id;
			$message = $this->field('failure_message');
			if (!empty($message)) {
				$errorMessage = $message . "\n" . $errorMessage;
			}
		}

		return $this->markJobFailed($id, $errorMessage);
	}

/**
 * Delete tasks by fields value
 *
 * @param array $data Data of task for deleting
 * @return bool Success
 */
	public function deleteTasks($data = null) {
		if (empty($data) || !is_array($data)) {
			return false;
		}

		$excludeFields = [
			'data',
			'failure_message',
			'workerkey',
		];
		$data = array_diff_key($data, array_flip($excludeFields));
		$conditions = [];
		foreach ($data as $field => $value) {
			$conditions[$this->alias . '.' . $field] = $value;
		}

		if (empty($conditions)) {
			return false;
		}

		$countTasks = $this->find('count', compact('conditions'));
		if (($countTasks == 0) || !$this->deleteAll($conditions)) {
			return false;
		}

		return true;
	}
}
