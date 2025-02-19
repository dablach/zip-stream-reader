<?php
namespace Dabla\ZipStreamReader;

class ZipEntry
{
	/** @var resource */
	private $stream;

	private int $mtime;

	private int $size;

	private string $name;

	/** @param resource $stream */
	public function __construct($stream, int $mtime, int $size, string $name) {
		$this->stream = $stream;
		$this->mtime = $mtime;
		$this->size = $size;
		$this->name = $name;
	}

	/** @param int<0, max> $length */
	public function read(int $length): string|false {
		return fread($this->stream, $length);
	}

	public function eof(): bool {
		return feof($this->stream);
	}

	public function getName(): string {
		return $this->name;
	}

	/** @return resource */
	public function getStream() {
		return $this->stream;
	}

	public function getMtime(): \DateTimeImmutable {
		return new \DateTimeImmutable('@' . $this->mtime);
	}

	public function getSize(): int {
		return $this->size;
	}
}
