<?php
namespace Dabla\ZipStreamReader;

/**
 * @implements \Iterator<ZipEntry>
 */
class ZipStreamReader implements \Iterator
{
	/** @var resource */
	private $fd;

	private ?ZipEntry $entry = null;

	private ?ZipEntryStreamWrapper $wrapper = null;

	private bool $done = false;

	/** @param resource $fd */
	public function __construct($fd) {
		$this->fd = $fd;
	}

	public static function open(string $url): self {
		return new self(fopen($url, 'rb') ?: throw new \Exception('Could not open '.$url));
	}

	public function rewind(): void {
		if($this->entry !== null || $this->done) {
			throw new \Exception('not rewindable');
		}
	}

	public function current(): ZipEntry {
		if($this->entry === null && !$this->done) {
			$this->readEntry();
		}
		return $this->entry ?? throw new \Exception('There are no more entries in this archive.');
	}

	public function key(): string {
		return $this->current()->getName();
	}

	public function valid(): bool {
		if($this->entry === null && !$this->done) {
			$this->readEntry();
		}
		return $this->entry !== null;
	}

	public function next(): void {
		if($this->entry !== null) {
			$ref = \WeakReference::create($this->entry);
			$this->entry = null;
			assert($this->wrapper !== null);
			if($ref->get() === null) {
				$this->wrapper->stream_close();
			} else {
				$this->wrapper->bufferAll();
			}
			$this->wrapper = null;
		}
		if(!$this->done) {
			$this->readEntry();
		}
	}

	private function readEntry(): void {
		$header = unpack(
			'Vsig/vversion/vbits/vcomp/vmdate/vmtime/Vcrc32/VcSize/VuSize/vnamelen/vxtralen',
			fread($this->fd, 30) ?: throw new \Exception('Unexpected end of content'),
		) ?: throw new \Exception('Cloud not parse local file header.');

		if($header['sig'] === 0x08064b50 || $header['sig'] === 0x02014b50) {
			$this->done = true;
			return;
		} else if($header['sig'] !== 0x04034b50) {
			throw new \Exception('Malformed zip');
		}

		if(($header['bits'] & 1) !== 0) {
			throw new \Exception('Encryption is not supported');
		}
		if(($header['bits'] & 8) !== 0) {
			throw new \Exception('This archive can not be read from stream, because at least one entry has no pre-known size.');
		}

		$name = fread($this->fd, $header['namelen']) ?: throw new \Exception('Unexpected end of content');

		$cSize = $header['cSize'];
		$uSize = $header['uSize'];

		if($header['xtralen'] > 0) {
			$extra = fread($this->fd, $header['xtralen']) ?: throw new \Exception('Unexpected end of content');
			for($i = 0; $i < $header['xtralen'];) {
				$x = unpack('vid/vlen', $extra, $i) ?: throw new \Exception('Could not parse local file header.');
				if($x['id'] === 1) {
					$size = unpack('PuSize/PcSize', $extra, $i+4) ?: throw new \Exception('Could not parse local file header.');
					$cSize = $size['cSize'];
					$uSize = $size['uSize'];
				}
				$i += $x['len']+4;
			}
		}

		[$stream, $this->wrapper] = ZipEntryStreamWrapper::createStream($this->fd, $cSize);

		switch($header['comp']) {
			case 0:
				break;
			case 8: // deflate
			case 9: // deflate64
				stream_filter_append($stream, 'zlib.inflate', STREAM_FILTER_READ, ['window' => -15]);
				break;
			case 12: // bzip2
				stream_filter_append($stream, 'bzip2.decompress', STREAM_FILTER_READ);
				break;
			default:
				throw new \Exception('Unsupported compression method');
		}

		$this->entry = new ZipEntry(
			$stream,
			$this->decodeMsdosDatetime($header['mdate'], $header['mtime']) ?: throw new \Exception('Invalid mtime'),
			$uSize,
			$name
		);
	}

	private function decodeMsdosDatetime(int $date, int $time): int|false {
		return mktime(
			$time >> 11,
			($time >> 5) & 0x3f,
			($time & 0x1f) * 2,
			$date & 0x1f,
			($date >> 5) & 0xf,
			($date >> 9) + 1980,
		);
	}
}

