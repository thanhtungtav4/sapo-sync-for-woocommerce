<?php
/**
 * Scheduled product mapping reconciliation wrapper.
 *
 * @package WooSapoSync
 */

namespace WooSapoSync\Application;

use WooSapoSync\Infrastructure\Sapo\Exception\SapoException;
use WooSapoSync\Infrastructure\WordPress\JobLock;
use WooSapoSync\Infrastructure\WordPress\SyncLogger;

defined('ABSPATH') || exit;

final class MappingSyncJob
{
	private MappingSynchronizer $synchronizer;

	public function __construct(MappingSynchronizer $synchronizer)
	{
		$this->synchronizer = $synchronizer;
	}

	public function run(): void
	{
		$lock = new JobLock('mapping', 900);
		if (! $lock->acquire()) {
			SyncLogger::log('info', 'Product mapping reconciliation skipped because another run is active.');
			return;
		}

		try {
			$report = $this->synchronizer->sync();
			SyncLogger::log('info', 'Product mapping reconciliation completed.', $report);
		} catch (SapoException $exception) {
			SyncLogger::log('warning', 'Product mapping reconciliation could not run.', ['error_code' => $exception->error_code()]);
		} catch (\Throwable $exception) {
			SyncLogger::log('error', 'Unexpected product mapping reconciliation failure.');
		} finally {
			$lock->release();
		}
	}
}
