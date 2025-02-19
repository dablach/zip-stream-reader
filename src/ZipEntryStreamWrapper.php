<?php
namespace Dabla\ZipStreamReader;

class ZipEntryStreamWrapper
{
	const WRAPPER_NAME = 'dabla.zip.entry';

	static bool $isRegistered = false;

	static ?self $instance = null;

	/** @var ?resource */
	public $context;

	/** @var resource */
	private $fd;

	private int $remaining;

	/**
	 * 0: no, 1: yes, 32-bit sizes, 2: yes, 64-bit sizes
	 */
	private int $hasDataDescriptor;

	private ?bool $blocking = null;

	private string $buffer = '';

	/**
	 * @param resource $fd
	 * @return array{resource, self}
	 */
	public static function createStream($fd, int $length, int $hasDataDescriptor): array {
		if(!self::$isRegistered) {
			stream_wrapper_register(self::WRAPPER_NAME, self::class);
			self::$isRegistered = true;
		}
		$stream = fopen(self::WRAPPER_NAME.'://', 'r', false, stream_context_create([
			self::WRAPPER_NAME => ['fd' => $fd, 'length' => $length, 'datadescr' => $hasDataDescriptor],
		])) ?: throw new \Exception('Cloud not open stream for zip entry.');
		assert(self::$instance !== null);
		$instance = self::$instance;
		self::$instance = null;
		return [$stream, $instance];
	}

	public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool {
		if(($mode !== 'r' && $mode !== 'rb') || empty($this->context)) {
			return false;
		}

		$options = stream_context_get_options($this->context);
		$this->fd = $options[self::WRAPPER_NAME]['fd'] ?? null;
		$this->remaining = $options[self::WRAPPER_NAME]['length'] ?? null;
		$this->hasDataDescriptor = $options[self::WRAPPER_NAME]['datadescr'];

		if(!is_resource($this->fd) || !is_int($this->remaining)) {
			return false;
		}

		self::$instance = $this;
		return true;
	}

	public function stream_read(int $length): string {
		while (($remaining = min($length - strlen($this->buffer), $this->remaining)) > 0) {
			$chunk = fread($this->fd, $remaining);
			if ($chunk === false) {
				throw new \Exception('Could not read chunk');
			}
			if(strlen($chunk) === 0) {
				if($this->blocking === null) {
					$meta = stream_get_meta_data($this->fd);
					$this->blocking = $meta['blocked'];
				}
				if($this->blocking === false) {
					break;
				}
				throw new \Exception('Unexpected empty read');
			}
			$this->remaining -= strlen($chunk);
			$this->buffer .= $chunk;
		}
		$chunk = substr($this->buffer, 0, $length);
		$this->buffer = substr($this->buffer, $length);
		return $chunk;
	}

	public function stream_eof(): bool {
		return $this->buffer === '' && $this->remaining === 0;
	}

	/** @return resource */
	public function stream_cast(int $as) {
		return $this->fd;
	}

	public function stream_set_option(int $option, int $arg1, int $arg2): void {
		if($option === STREAM_OPTION_BLOCKING) {
			$this->blocking = $arg1 === 1;
		}
	}

	public function bufferAll(): void {
		while($this->remaining > 0) {
			$chunk = fread($this->fd, $this->remaining);
			if($chunk === false) {
				throw new \Exception('could not bufferAll');
			}
			$this->remaining -= strlen($chunk);
			$this->buffer .= $chunk;
		}
		$this->consumeDataDescriptor();
	}

	public function stream_close(): void {
		if ($this->remaining > 0) {
			if(fseek($this->fd, $this->remaining, SEEK_CUR) !== 0) {
				throw new \Exception('could not discard');
			}
			$this->remaining = 0;
		}
		$this->buffer = '';
		$this->consumeDataDescriptor();
	}

	private function consumeDataDescriptor(): void {
		if ($this->hasDataDescriptor === 0) return;
		$is64bit = $this->hasDataDescriptor === 2;
		$descriptor = unpack(
			'Vsig',
			fread($this->fd, $is64bit ? 20 : 12) ?: throw new \Exception('Unexpected end of content'),
		);
		if ($descriptor['sig'] === 0x08074b50) {
			fread($this->fd, 4);
		}
	}
}
