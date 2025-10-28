<?php

namespace focusbp\OpenAIResponsePhp;

/**
FileRecorder

Saves conversation history to a specified file in a specified directory.
The format is JSON.

Details:
The combination of $dir/$filename provided to the constructor is treated as a single "conversation log".
read(): Reads that JSON file and returns it as an array (returns [] if it doesn't exist).
write(): Overwrites the entire file.
append(): Appends one entry to the end.
*/
class FileRecorder implements \focusbp\OpenAIResponsePhp\Recorder {

	private $dir;
	private $filename;
	private $path;

	/**
	 * @param string $dir Path to the destination directory (will be created if it does not exist)
	 * @param string $filename The name of the file to save (e.g. "conv_123.json")
	 */
	public function __construct(string $dir, string $filename) {
		$this->dir = rtrim($dir, DIRECTORY_SEPARATOR);
		$this->filename = $filename;
		$this->path = $this->dir . DIRECTORY_SEPARATOR . $this->filename;

		if (!is_dir($this->dir)) {
			mkdir($this->dir, 0777, true);
		}

		if (!file_exists($this->path)) {
			$this->write([]);
		}
	}

	/** @inheritdoc */
	public function read(): array {
		if (!file_exists($this->path)) {
			return [];
		}

		$json = @file_get_contents($this->path);
		if ($json === false || $json === '') {
			return [];
		}

		$data = json_decode($json, true);
		if (!is_array($data)) {
			return [];
		}

		return $data;
	}

	/** @inheritdoc */
	public function write(array $messages): void {
		$json = json_encode(
			array_values($messages),
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
		);

		file_put_contents($this->path, $json);
	}

	/** @inheritdoc */
	public function append(string $role, $content): void {
		$msgs = $this->read();
		$msgs[] = [
		    'role' => $role,
		    'content' => $content,
		];
		$this->write($msgs);
	}


}
