<?php
/**
 * Scheduled inventory reconciliation wrapper.
 *
 * @package WooSapoSync
 */

namespace WooSapoSync\Application;

use WooSapoSync\Infrastructure\Sapo\ErrorCode;
use WooSapoSync\Infrastructure\Sapo\Exception\SapoException;
use WooSapoSync\Infrastructure\WordPress\JobLock;
use WooSapoSync\Infrastructure\WordPress\SyncLogger;

defined('ABSPATH') || exit;

final class InventorySyncJob
{
	private InventoryReconciler $reconciler;

	public function __construct(InventoryReconciler $reconciler)
	{
		$this->reconciler = $reconciler;
	}

	public function run(): void
	{
		$lock = new JobLock('inventory', 240);
		if (! $lock->acquire()) {
			SyncLogger::log('info', 'Inventory reconciliation skipped because another run is active.');
			return;
		}

		try {
			$report = $this->reconciler->sync_configured();
			SyncLogger::log('info', 'Inventory reconciliation completed.', [
				'mode' => $report['mode'],
				'mapped' => $report['mapped'],
				'updated' => $report['updated'],
				'differences' => $report['differences'],
			]);
		} catch (SapoException $exception) {
			if (ErrorCode::AUTH === $exception->error_code()) {
				CapabilityGate::invalidate();
			}
			SyncLogger::log('warning', 'Inventory reconciliation could not run.', ['error_code' => $exception->error_code()]);
		} catch (\Throwable $exception) {
			SyncLogger::log('error', 'Unexpected inventory reconciliation failure.');
		} finally {
			$lock->release();
		}
	}
}
