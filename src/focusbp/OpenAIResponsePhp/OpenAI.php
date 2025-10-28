<?php

namespace focusbp\OpenAIResponsePhp;

/**
 * Utility for easily working with the OpenAI Responses API / Vector Stores
 * - Compatible with PHP 7.3 (does not use typed properties)
 * - Dependencies: cURL, JSON extension
 */
class OpenAI {

	/** @var string */
	private $apiKey;

	/** @var string */
	private $baseUrl;

	/** @var string */
	private $model;

	/** @var string|null */
	private $toolsDir;

	/** @var string|null */
	private $vectorSyncDir;

	/** @var array<string, FunctionTool> name => instance */
	private $tools = [];

	/** @var string|null */
	private $vectorStoreId;

	/** @var string|null Vector Store Name */
	private $vectorStoreName;

	/** @var string|null Last Responses API response_id */
	private $responseId = null;

	/** Logfile path */
	private $logfile = null;

	/** Maximum number of characters to keep in the log (truncate if exceeded) */
	private $logTruncate = 100000;
	private $logPrettyJson = true;
	private $ctl = null;
	private $recorder = null;
	private $statusmanager = null;

	/**
	 * @param string      $apiKey             OpenAI API key
	 * @param string|null $vectorSyncDir      Local directory to sync with the Vector Store (nullable)
	 * @param string|null $vectorStoreName    Display name of the Vector Store (nullable)
	 * @param string|null $vectorStoreID      ID of the Vector Store, if already created (nullable)
	 * @param string|null $toolsDir           Directory containing FunctionTool implementations (nullable)
	 * @param string      $model              Model to use (e.g. 'gpt-4.1-mini')
	 * @param string      $baseUrl            Base URL of the API (typically https://api.openai.com/v1)
	 * @param string|null $logfile            Path to a log file for request/response debugging (nullable)
	 * @param \focusbp\OpenAIResponsePhp\Recorder $recorder
	 *                                       Recorder instance for saving conversation history (nullable)
	 * @param \focusbp\OpenAIResponsePhp\StatusManager $statusmanager
	 *                                       Status manager instance for tracking run state, etc. (nullable)
	 * @param \focusbp\OpenAIResponsePhp\Controller $ctl
	 *                                       Application controller / service container.
	 *                                       Can be a subclass of Controller. Used to pass shared resources
	 *                                       such as DB connections. (nullable)
	 */
	public function __construct(
		$apiKey,
		$vectorSyncDir = null,
		$vectorStoreName = null,
		$vectorStoreID = null,
		$toolsDir = null,
		$model = 'gpt-5',
		$logfile = null,
		\focusbp\OpenAIResponsePhp\Recorder $recorder = null,
		\focusbp\OpenAIResponsePhp\StatusManager $statusmanager = null,
		\focusbp\OpenAIResponsePhp\Controller $ctl = null
	) {
		$this->apiKey = $apiKey;
		$this->vectorSyncDir = $vectorSyncDir;
		$this->vectorStoreName = $vectorStoreName;
		$this->vectorStoreId = $vectorStoreID;
		$this->toolsDir = $toolsDir;
		$this->model = $model;
		if (!empty($logfile)) {
			$this->logfile = $logfile;
		}
		$this->ctl = $ctl;

		if ($this->toolsDir && is_dir($this->toolsDir)) {
			$this->loadToolsFromDirectory($this->toolsDir);
		}

		$this->recorder = $recorder;
		$this->statusmanager = $statusmanager;

		$this->baseUrl = 'https://api.openai.com/v1';
	}

	/** Clears the conversation history (also discards the response_id) */
	public function clear_messages(): void {
		$this->writeMessages([]);   // ★ セッションを空に
		$this->responseId = null;
	}

	/** Returns the current conversation history (read-only) */
	public function get_messages(): array {
		return $this->readMessages();
	}

	/** Adds a system message */
	public function add_system(string $content): void {
		$this->appendMessage('system', $content);
	}

	/** Adds a user message */
	public function add_user(string $content): void {
		$this->appendMessage('user', $content);
	}

	/**
	 * 
	 * Sends a message, automatically performs Function Calling if needed,
	 * and returns the final answer.
	 * The conversation history is maintained internally in this class.
	 * 
	 * @param mixed $input string
	 * string: Added as a new user message
	 * 
	 * @return \focusbp\OpenAIResponsePhp\Response|null Final model response
	 * 
	 */
	public function respond($input = null): ?\focusbp\OpenAIResponsePhp\Response {

		if (empty($input)) {
			return null;
		}

		$this->appendMessage('user', $input);
		$messages = $this->get_messages();

		$toolDefs = $this->buildToolDefinitions();

		// ★ file_search
		if ($this->vectorStoreId) {
			$toolDefs[] = [
			    'type' => 'file_search',
			    'vector_store_ids' => [$this->vectorStoreId],
			];
		}

		$request = [
		    'model' => $this->model,
		    'input' => $messages,
		    'parallel_tool_calls' => true,
		];
		if (!empty($toolDefs)) {
			$request['tools'] = $toolDefs;
		}

		// First
		$resp = $this->post('/responses', $request, "Thinking your request: " . $input);
		$this->responseId = $this->extractResponseId($resp);
		$this->appendAssistantMessagesFromResponse($resp);

		// Function Calling Loop
		$round = 0;
		while ($this->hasToolCalls($resp) && $round < 5) {
			$round++;
			$items = [];
			$names = [];
			foreach ($this->extractToolCalls($resp) as $call) {
				$name = (string) ($call['name'] ?? '');
				$callId = (string) ($call['call_id'] ?? '');

				if ($callId === '') {
					// skip
					continue;
				}

				$tool = $this->getToolByName($name);

				$argsRaw = $call['arguments'] ?? '{}';
				if (!is_string($argsRaw)) {
					$argsRaw = json_encode($argsRaw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
				}
				$args = json_decode($argsRaw, true);
				if (!is_array($args)) {
					$args = [];
				}

				$output = '';
				try {
					if (!$tool) {
						$output = 'error: tool not found: ' . $name;
						$names[] = $name . "(" . $output . ") ";
					} else {
						$result = $tool->execute($this->ctl, $args);
						$output = is_string($result) ? $result : json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
						// Status Message
						if (is_array($result)) {
							$result_keys = array_keys($result);
							if (!empty($result["fields"])) {
								$field_keys = [];
								foreach ($result["fields"] as $f) {
									$field_keys[] = $f["field_name"];
								}
								$result_keys = array_merge($result_keys, $field_keys);
							}
						} elseif (is_object($result)) {
							// For Object
							$result_keys = array_keys(get_object_vars($result));
						} else {
							// Others
							$result_keys = [];
						}
						$names[] = $name . "(Description:" . $tool->description() . " Data:" . implode(",", $result_keys) . ") ";
					}
				} catch (\Throwable $e) {
					$output = 'error: ' . $e->getMessage();
					$names[] = $name . "(" . $output . ") ";
				}


				$items[] = [
				    'type' => 'function_call_output',
				    'call_id' => $callId,
				    'output' => $output,
				];
			}

			$nextReq = [
			    'model' => $this->model,
			    'previous_response_id' => $this->responseId,
			    'input' => $items,
			    'tools' => $toolDefs,
			];

			$resp = $this->post('/responses', $nextReq, "Function: " . implode(",", $names));

			$this->responseId = $this->extractResponseId($resp);
			$this->appendAssistantMessagesFromResponse($resp);
		}

		$this->set_status_msg("END");

		// Return Response Object
		return new \focusbp\OpenAIResponsePhp\Response($resp, $this->get_messages());
	}

	private function set_status_msg($msg) {
		$this->statusmanager->set_status($msg);
	}

	/**
	 * 
	 * Fully rebuilds the Vector Store using the contents of the specified directory
	 * (delete everything, then re-upload all files).
	 * 
	 * Uses the Vector Store name provided in the constructor
	 * (or falls back to the directory name if not specified).
	 * 
	 */
	public function syncVectorStore(): ?string {
		if (!$this->vectorSyncDir || !is_dir($this->vectorSyncDir)) {
			return null;
		}

		$flg_create = false;
		if (!$this->vectorStoreId) {
			$flg_create = true;
		} else {
			try {
				$vsRes = $this->get("/vector_stores/{$this->vectorStoreId}");
				if (
					!is_array($vsRes) ||
					!isset($vsRes['id']) ||
					(string) $vsRes['id'] !== $this->vectorStoreId
				) {
					$flg_create = true;
				}
			} catch (\Throwable $e) {
				$flg_create = true;
			}
		}

		// Create New
		if ($flg_create) {
			$this->vectorStoreId = $this->findVectorStoreIdByName($this->vectorStoreName);

			if (!$this->vectorStoreId) {
				$this->vectorStoreId = $this->createVectorStore();
			}
		}

		// Delete All Files
		$existing = $this->listVectorStoreFileIds($this->vectorStoreId);
		foreach ($existing as $fid) {
			$this->deleteVectorStoreFile($this->vectorStoreId, $fid);
			try {
				// Delete File
				$this->deleteUploadedFile($fid);
			} catch (Exception $e) {
				// Nothing to do
			}
		}

		// Upload all files
		$paths = $this->listLocalFiles($this->vectorSyncDir);
		$fileIds = [];
		foreach ($paths as $p) {
			$fileIds[] = $this->uploadFile($p, 'assistants');
		}

		// 3) attach all files to vector store
		if (!empty($fileIds)) {
			$this->createVectorStoreFileBatch($this->vectorStoreId, $fileIds);
		}

		return $this->vectorStoreId;
	}


	private function loadToolsFromDirectory(string $dir): void {
		$rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
		foreach ($rii as $file) {
			if ($file->isDir())
				continue;
			if (substr($file->getFilename(), -4) !== '.php')
				continue;

			$before = get_declared_classes();
			require_once $file->getPathname();
			$after = get_declared_classes();
			$newCls = array_diff($after, $before);

			foreach ($newCls as $fqcn) {
				$impl = class_implements($fqcn) ?: [];
				if (!in_array(FunctionTool::class, $impl, true))
					continue;

				$ref = new \ReflectionClass($fqcn);
				if ($ref->isAbstract())
					continue;

				/** @var FunctionTool $inst */
				$inst = $ref->newInstance();
				$this->tools[$inst->name()] = $inst;
			}
		}
	}

	function buildToolDefinitions(): array {
		$defs = [];

		foreach ($this->tools as $tool) {
			$schema = $tool->parameters();
			if (!is_array($schema))
				$schema = [];

			// 1) object
			$schema['type'] = 'object';

			// 2) properties
			$propKeys = [];
			if (!isset($schema['properties'])) {
				$schema['properties'] = new \stdClass(); // empty {}
			} elseif ($schema['properties'] instanceof \stdClass) {
				$propKeys = []; // 空オブジェクト
			} elseif (is_array($schema['properties'])) {
				$propKeys = array_keys($schema['properties']);
				if (empty($propKeys)) {
					$schema['properties'] = new \stdClass(); // empty {}
				}
			} else {
				$schema['properties'] = new \stdClass();
			}

			// 3) required
			$schema['required'] = $propKeys;
			
			// 4) additional properies is always false
			if (!isset($schema['additionalProperties'])) {
				$schema['additionalProperties'] = false;
			}

			$defs[] = [
			    'type' => 'function',
			    'name' => (string) $tool->name(),
			    'description' => (string) $tool->description(),
			    'parameters' => $schema,
			    'strict' => true,
			];
		}

		return $defs;
	}

	private function extractResponseId(array $resp): ?string {
		return isset($resp['id']) ? $resp['id'] : (isset($resp['response']['id']) ? $resp['response']['id'] : null);
	}

	private function appendAssistantMessagesFromResponse(array $resp): void {
		if (!isset($resp['output']) || !is_array($resp['output']))
			return;

		foreach ($resp['output'] as $item) {
			if (!isset($item['type']) || $item['type'] !== 'message')
				continue;

			$msg = $this->normalizeMessageItem($item);
			if (!$msg)
				continue;

			$role = isset($msg['role']) ? $msg['role'] : 'assistant';

			$content = '';
			if (isset($msg['content']) && is_array($msg['content'])) {
				foreach ($msg['content'] as $block) {
					if (is_array($block)) {
						if (isset($block['text'])) {
							$content .= is_string($block['text']) ? $block['text'] : json_encode($block['text'], JSON_UNESCAPED_UNICODE);
						} elseif (isset($block['content'])) {
							$content .= is_string($block['content']) ? $block['content'] : json_encode($block['content'], JSON_UNESCAPED_UNICODE);
						}
					} elseif (is_string($block)) {
						$content .= $block;
					}
				}
			} elseif (isset($msg['content']) && is_string($msg['content'])) {
				$content = $msg['content'];
			} else {
				$content = json_encode($msg, JSON_UNESCAPED_UNICODE);
			}

			$this->appendMessage($role, $content);
		}
	}

	private function listLocalFiles(string $dir): array {
		$rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
		$out = [];
		foreach ($rii as $file) {
			if ($file->isDir())
				continue;
			$out[] = $file->getPathname();
		}
		return $out;
	}

	private function createVectorStore(): string {
		$name = $this->vectorStoreName;
		$payload = ['name' => $name];
		$resp = $this->post('/vector_stores', $payload);
		if (empty($resp['id'])) {
			throw new \RuntimeException('Vector Store creating error.');
		}
		return $resp['id'];
	}

	private function listVectorStoreFileIds(string $vectorStoreId): array {
		$ids = [];
		$after = null;

		if ($vectorStoreId === '') {
			throw new \InvalidArgumentException('vectorStoreId is empty.');
		}

		do {
			$qs = $after ? ['after' => $after] : [];
			$res = $this->get("/vector_stores/{$vectorStoreId}/files", $qs);

			if (isset($res['data']) && is_array($res['data'])) {
				foreach ($res['data'] as $row) {
					if (isset($row['id']))
						$ids[] = $row['id'];
				}
			}

			$hasMore = isset($res['has_more']) ? (bool) $res['has_more'] : false;
			$after = $hasMore && isset($res['last_id']) ? $res['last_id'] : null;
		} while (!empty($after));

		return $ids;
	}

	private function deleteVectorStoreFile(string $vectorStoreId, string $fileId): void {
		$this->delete("/vector_stores/{$vectorStoreId}/files/{$fileId}");
	}

	private function uploadFile(string $path, string $purpose = 'assistants'): string {
		if (!is_readable($path)) {
			throw new \RuntimeException("Can't read file: " . $path);
		}

		$fields = [
		    'file' => new \CURLFile($path, mime_content_type($path), basename($path)),
		    'purpose' => $purpose,
		];

		$resp = $this->postMultipart('/files', $fields);
		if (empty($resp['id'])) {
			throw new \RuntimeException('Upload failure: ' . basename($path));
		}
		return $resp['id'];
	}

	private function createVectorStoreFileBatch(string $vectorStoreId, array $fileIds): array {
		$payload = ['file_ids' => array_values($fileIds)];
		return $this->post("/vector_stores/{$vectorStoreId}/file_batches", $payload);
	}

	private function get(string $path, array $query = []): array {
		if (!empty($query)) {
			$path .= (strpos($path, '?') === false ? '?' : '&') . http_build_query($query);
		}
		return $this->request('GET', $path, null);
	}

	private function post(string $path, array $json, $status_message = ''): array {
		$this->set_status_msg($status_message);
		session_write_close();
		$res = $this->request('POST', $path, $json, ['Content-Type: application/json']);
		session_start();
		return $res;
	}

	private function delete(string $path): array {
		return $this->request('DELETE', $path, null);
	}

	private function postMultipart(string $path, array $fields): array {
		return $this->request('POST', $path, $fields, [], true);
	}

	private function request(string $method, string $path, $data = null, array $headers = [], bool $isMultipart = false): array {
		$url = $this->baseUrl . $path;
		$ch = curl_init($url);

		$defaultHeaders = [
		    'Authorization: Bearer ' . $this->apiKey,
		];
		if (!$isMultipart) {
			$defaultHeaders[] = 'Accept: application/json';
		}

		$finalHeaders = array_merge($defaultHeaders, $headers);

		if (($method === 'POST' || $method === 'PATCH') && $data !== null) {
			if ($isMultipart) {
				$postFields = $data;
			} else {
				$json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
				if ($json === false) {
					throw new \RuntimeException('Failed to json_encode request payload');
				}
				$postFields = $json;

				$hasCT = false;
				foreach ($finalHeaders as $h) {
					if (stripos($h, 'Content-Type:') === 0) {
						$hasCT = true;
						break;
					}
				}
				if (!$hasCT) {
					$finalHeaders[] = 'Content-Type: application/json';
				}
			}
		}

		$opts = [
		    CURLOPT_RETURNTRANSFER => true,
		    CURLOPT_CUSTOMREQUEST => $method,
		    CURLOPT_HTTPHEADER => $finalHeaders,
		    CURLOPT_TIMEOUT => 300,
		];
		if (isset($postFields)) {
			$opts[CURLOPT_POSTFIELDS] = $postFields;
		}

		$this->log([
		    'direction' => '->',
		    'method' => $method,
		    'path' => $path,
		    'headers' => $this->sanitizeHeaderLines($finalHeaders),
		    'body' => $this->prepareBodyForLog(isset($postFields) ? $postFields : $data),
		]);

		curl_setopt_array($ch, $opts);

		if (function_exists('set_time_limit')) {
			set_time_limit(300);
		}
		ini_set('max_execution_time', '300');

		$raw = curl_exec($ch);

		if ($raw === false) {
			$err = curl_error($ch);
			curl_close($ch);
			throw new \RuntimeException('cURL error: ' . $err);
		}

		$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		$resp = json_decode($raw, true);

		$this->log([
		    'direction' => '<-',
		    'status' => $status ?? null,
		    'headers' => null,
		    'body' => $resp !== null ? $resp : $this->prepareBodyForLog($raw),
		]);

		if ($status >= 400) {
			$msg = isset($resp['error']['message']) ? $resp['error']['message'] : $raw;
			throw new \RuntimeException("HTTP {$status}: {$msg}");
		}

		return is_array($resp) ? $resp : ['raw' => $raw];
	}

	private function findVectorStoreIdByName(string $name): ?string {
		$after = null;
		do {
			$params = ['limit' => 100];
			if ($after)
				$params['after'] = $after;

			$res = $this->get('/vector_stores', $params);

			if (isset($res['data']) && is_array($res['data'])) {
				foreach ($res['data'] as $row) {
					$rowName = isset($row['name']) ? (string) $row['name'] : '';
					if ($rowName === $name) {
						return isset($row['id']) ? (string) $row['id'] : null;
					}
				}
			}

			$hasMore = isset($res['has_more']) ? (bool) $res['has_more'] : false;
			$after = $hasMore && isset($res['last_id']) ? (string) $res['last_id'] : null;
		} while (!empty($after));

		return null;
	}

	private function normalizeMessageItem(array $item): ?array {
		if (isset($item['message']) && is_array($item['message'])) {
			return $item['message']; // 旧式
		}
		if (isset($item['content'])) {
			return [
			    'role' => isset($item['role']) ? $item['role'] : 'assistant',
			    'content' => $item['content'], // 新式
			];
		}
		return null;
	}

	private function readMessages(): array {
		return $this->recorder->read();
	}

	private function writeMessages(array $messages): void {
		$this->recorder->write($messages);
	}

	private function appendMessage(string $role, $content): void {
		$this->recorder->append($role, $content);
	}

	private function getToolByName(string $name): ?\focusbp\OpenAIResponsePhp\FunctionTool {
		if ($name === '') {
			return null;
		}

		// 1
		if (isset($this->tools[$name]) && $this->tools[$name] instanceof \focusbp\OpenAIResponsePhp\FunctionTool) {
			return $this->tools[$name];
		}

		// 2
		if (is_array($this->tools)) {
			foreach ($this->tools as $tool) {
				if ($tool instanceof \focusbp\OpenAIResponsePhp\FunctionTool) {
					if (method_exists($tool, 'name') && $tool->name() === $name) {
						return $tool;
					}
				}
			}
		}

		// 該当なし
		return null;
	}

	private function extractToolCalls(array $resp): array {
		$calls = [];
		foreach (($resp['output'] ?? []) as $out) {
			if (($out['type'] ?? null) === 'function_call') {
				$calls[] = [
				    'name' => $out['name'] ?? null,
				    'arguments' => $out['arguments'] ?? '{}', // JSON文字列想定
				    'call_id' => $out['call_id'] ?? null,
				];
			}
		}
		return $calls;
	}

	public function log($text): void {
		if (!$this->logfile)
			return;

		if (is_array($text) || is_object($text)) {
			$json = json_encode($text, $this->logPrettyJson ? JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES : 0
			);
			$text = $json !== false ? $json : print_r($text, true);
		} elseif (!is_string($text)) {
			$text = (string) $text;
		}

		if ($this->logTruncate > 0 && strlen($text) > $this->logTruncate) {
			$text = substr($text, 0, $this->logTruncate) . "\n...[truncated]";
		}

		@file_put_contents(
				$this->logfile,
				'[' . date('c') . "]\n" . $text . "\n\n",
				FILE_APPEND
			);
	}

	private function sanitizeHeaderLines(array $lines) {
		$out = [];
		foreach ($lines as $h) {
			if (stripos($h, 'Authorization:') === 0) {
				$out[] = 'Authorization: ***REDACTED***';
			} else {
				$out[] = $h;
			}
		}
		return $out;
	}

	private function prepareBodyForLog($body) {
		if (is_string($body))
			return $body;

		if (is_array($body) || is_object($body)) {
			$json = json_encode($body, $this->logPrettyJson ? JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES : 0
			);
			return $json !== false ? $json : print_r($body, true);
		}

		return (string) $body;
	}

	public function respondStream($messages, array $options = []): void {
		$req = [
		    'model' => $this->model,
		    'stream' => true,
		    'input' => $messages,
		];

		$this->sseHeaders();

		$this->curlStream('/responses', $req);
	}

	private function sseHeaders(): void {
		header('Content-Type: text/event-stream; charset=utf-8');
		header('Cache-Control: no-cache');
		header('Connection: keep-alive');
		header('X-Accel-Buffering: no');
		@ob_end_flush();
		@ob_implicit_flush(true);
	}

	private function curlStream(string $path, array $req): void {
		$ch = curl_init($this->baseUrl . $path);
		curl_setopt_array($ch, [
		    CURLOPT_RETURNTRANSFER => false,
		    CURLOPT_POST => true,
		    CURLOPT_HTTPHEADER => [
			'Authorization: Bearer ' . $this->apiKey,
			'Content-Type: application/json',
			'Accept: text/event-stream',
		    ],
		    CURLOPT_POSTFIELDS => json_encode($req, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
		    CURLOPT_WRITEFUNCTION => function ($ch, $chunk) {
			    echo $chunk;
			    @ob_flush();
			    flush();
			    return strlen($chunk);
		    },
		    CURLOPT_TIMEOUT => 0,
		]);
		curl_exec($ch);
		curl_close($ch);
	}

	private function extractFunctionCalls(array $resp): array {
		$calls = [];
		if (!isset($resp['output']) || !is_array($resp['output']))
			return $calls;

		foreach ($resp['output'] as $item) {
			if (!is_array($item))
				continue;
			if (($item['type'] ?? '') !== 'function_call')
				continue;

			$name = isset($item['name']) ? (string) $item['name'] : '';
			$args = isset($item['arguments']) ? (string) $item['arguments'] : '{}';
			$callId = isset($item['call_id']) ? (string) $item['call_id'] : '';
			if ($name !== '' && $callId !== '') {
				$calls[] = ['name' => $name, 'arguments' => $args, 'call_id' => $callId];
			}
		}
		return $calls;
	}

	private function hasToolCalls(array $resp): bool {
		return count($this->extractFunctionCalls($resp)) > 0;
	}

	private function deleteUploadedFile(string $fileId): void {
		try {
			$this->delete("/files/{$fileId}");
		} catch (\Throwable $e) {
			//
		}
	}

}
