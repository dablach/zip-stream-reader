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

	private ?array $centralDirectory = null;

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
		$offset = ftell($this->fd);

		if ($this->centralDirectory !== null) {
			$nextOffset = null;
			foreach ($this->centralDirectory as $entryOffset => $cdEntry) {
				if ($entryOffset >= $offset && ($nextOffset === null || $nextOffset > $entryOffset)) {
					$nextOffset = $entryOffset;
					$header = $cdEntry;
				}
			}
			if ($nextOffset !== null) {
				$offset = $nextOffset;
				fseek($this->fd, $offset + 30, SEEK_SET);
			}
		}

		if (!isset($header)) {
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

			$header['name'] = fread($this->fd, $header['namelen']) ?: throw new \Exception('Unexpected end of content');
			if ($header['xtralen'] > 0) {
				$header['extra'] = fread($this->fd, $header['xtralen']) ?: throw new \Exception('Unexpected end of content');
			}

			if(($header['bits'] & 8) !== 0) {
				$this->centralDirectory ??= $this->readCentralDirectory();
				$header = $this->centralDirectory[$offset] ?? throw new \Exception('entry has no preknown size and centry directory record could not be found.');
			}
		}

		if(($header['bits'] & 1) !== 0) {
			throw new \Exception('Encryption is not supported');
		}

		$cSize = $header['cSize'];
		$uSize = $header['uSize'];
		$hasDataDescriptor = ($header['bits'] & 8) === 0 ? 0 : 1;

		if($header['xtralen'] > 0) {
			for($i = 0; $i < $header['xtralen'];) {
				$x = unpack('vid/vlen', $header['extra'], $i) ?: throw new \Exception('Could not parse local file header.');
				if($x['id'] === 1 && $cSize === 0xffffffff && $uSize === 0xffffffff) {
					$size = unpack('PuSize/PcSize', $header['extra'], $i+4) ?: throw new \Exception('Could not parse local file header.');
					$cSize = $size['cSize'];
					$uSize = $size['uSize'];
					if ($hasDataDescriptor === 1) {
						$hasDataDescriptor = 2;
					}
				}
				$i += $x['len']+4;
			}
		}

		[$stream, $this->wrapper] = ZipEntryStreamWrapper::createStream($this->fd, $cSize, $hasDataDescriptor);

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
			$header['name']
		);
	}

	private function readCentralDirectory(): array {
		$offsetShift = ftell($this->fd);
		if (!stream_get_meta_data($this->fd)['seekable']) {
			$mem = fopen('php://temp', 'r+') ?: throw new \Exception('could not open tempfile');
			stream_copy_to_stream($this->fd, $mem);
			rewind($mem);
			fclose($this->fd);
			$this->fd = $mem;
		}
		$origPos = ftell($this->fd);

		$rpos = -1024;
		$cdOffset = $cdLen = $totalLen = null;
		do {
			fseek($this->fd, $rpos, SEEK_END);
			$totalLen ??= ftell($this->fd) - $rpos;
			$chunk = fread($this->fd, 1024);
			$p = strrpos($chunk, "\x50\x4b\x05\x06");
			if ($p !== false) {
				$data = unpack('Vlen/Voffset', substr($chunk, $p + 12, 8))
					?: throw new \Exception('could not read end of central directory record');
				$cdOffset = $data['offset'] - $offsetShift;
				$cdLen = $data['len'];
			}
			$rpos -= 1008; // go back less than chunk size bytes, so the EOCD record lies completely inside the chunk
		} while ($cdOffset === null && $totalLen + $rpos > $origPos);

		if ($cdOffset === null || $cdLen === null) throw new \Exception('could not locate central directory');
		fseek($this->fd, $cdOffset, SEEK_SET);
		$cdBytes = fread($this->fd, $cdLen) ?: throw new \Exception('unexpected end of data');
		$offset = 0;
		$directory = [];
		while ($offset < $cdLen) {
			$header = unpack(
				'Vsig/vversion/vexver/vbits/vcomp/vmdate/vmtime/Vcrc32/VcSize/VuSize/vnamelen/vxtralen/vcommlen/vndisk/vintattr/Vextattr/Voffset',
				$cdBytes,
				$offset
			) ?: throw new \Exception('Cloud not parse central directory entry.');
			if ($header['sig'] !== 0x02014b50) {
				throw new \Exception('Malformed zip');
			}
			$offset += 46;
			$header['name'] = substr($cdBytes, $offset, $header['namelen']);
			$offset += $header['namelen'];
			$header['extra'] = substr($cdBytes, $offset, $header['xtralen']);
			$offset += $header['xtralen'];
			$header['comment'] = substr($cdBytes, $offset, $header['commlen']);
			$offset += $header['commlen'];
			$directory[$header['offset']] = $header;
		}

		fseek($this->fd, $origPos, SEEK_SET);
		return $directory;
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
