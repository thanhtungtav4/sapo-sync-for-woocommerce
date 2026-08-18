<?php
/**
 * Normalized exception raised at the Sapo integration boundary.
 *
 * @package WooSapoSync
 */

namespace WooSapoSync\Infrastructure\Sapo\Exception;

defined('ABSPATH') || exit;

use RuntimeException;

class SapoException extends RuntimeException
{
	private string $error_code;

	/**
	 * @param array<string, mixed> $context
	 */
	public function __construct(string $error_code, string $message, private array $context = [])
	{
		$this->error_code = $error_code;
		parent::__construct($message);
	}

	public function error_code(): string
	{
		return $this->error_code;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function context(): array
	{
		return $this->context;
	}
}
