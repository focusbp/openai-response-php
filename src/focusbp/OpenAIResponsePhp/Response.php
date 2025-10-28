<?php

namespace focusbp\OpenAIResponsePhp;

class Response {

	/** @var array */
	private $raw;

	/** @var array<int, array{role:string,content:mixed}>|null */
	private $history;

	public function __construct(array $raw, ?array $history = null) {
		$this->raw = $raw;
		$this->history = $history;
	}

	public function toArray(): array {
		return $this->raw;
	}

	public function response_id(): ?string {
		if (isset($this->raw['id']))
			return $this->raw['id'];
		if (isset($this->raw['response']['id']))
			return $this->raw['response']['id'];
		return null;
	}

	public function get_text(): string {
		return implode("\n\n", $this->get_text_blocks());
	}

	public function get_text_blocks(): array {
		$out = [];
		if (!isset($this->raw['output']) || !is_array($this->raw['output']))
			return $out;

		foreach ($this->raw['output'] as $item) {
			if (!isset($item['type']) || $item['type'] !== 'message')
				continue;

			$msg = $this->extract_message_from_output_item($item);
			if (!$msg)
				continue;

			$out[] = $this->flatten_message_content(isset($msg['content']) ? $msg['content'] : '');
		}
		return $out;
	}

	public function get_tool_calls(): array {
		$calls = [];
		if (!isset($this->raw['output']) || !is_array($this->raw['output']))
			return $calls;

		foreach ($this->raw['output'] as $item) {
			if (isset($item['type']) && $item['type'] === 'tool_call') {
				$args = isset($item['arguments']) ? $item['arguments'] : '{}';
				$parsed = is_array($args) ? $args : json_decode((string) $args, true);
				if (!is_array($parsed))
					$parsed = [];
				$calls[] = [
				    'id' => isset($item['id']) ? $item['id'] : null,
				    'name' => isset($item['name']) ? $item['name'] : null,
				    'arguments' => $parsed,
				    'raw' => $item,
				];
			}
			if (isset($item['type']) && $item['type'] === 'message' && isset($item['message']['tool_calls']) && is_array($item['message']['tool_calls'])) {
				foreach ($item['message']['tool_calls'] as $tc) {
					$args = isset($tc['function']['arguments']) ? $tc['function']['arguments'] : '{}';
					$parsed = is_array($args) ? $args : json_decode((string) $args, true);
					if (!is_array($parsed))
						$parsed = [];
					$calls[] = [
					    'id' => isset($tc['id']) ? $tc['id'] : null,
					    'name' => isset($tc['function']['name']) ? $tc['function']['name'] : null,
					    'arguments' => $parsed,
					    'raw' => $tc,
					];
				}
			}
		}
		return $calls;
	}

	public function get_assistant_messages_raw(): array {
		$out = [];
		if (!isset($this->raw['output']) || !is_array($this->raw['output']))
			return $out;

		foreach ($this->raw['output'] as $item) {
			if (isset($item['type']) && $item['type'] === 'message') {
				$msg = $this->extract_message_from_output_item($item);
				if ($msg)
					$out[] = $msg;
			}
		}
		return $out;
	}

	/**
	 * 
	 * Returns the conversation history (old → new), excluding any "system" messages.
	 * 
	 * Behavior:
	 * If session history was provided by OpenAI_class, that history is used.
	 * Otherwise, falls back to using the messages contained in this response
	 * (assistant messages only).
	 * 
	 * @return array<int, array{role:string,content:string}>
	 * 
	 */
	public function get_history(): array {
		$out = [];

		if (is_array($this->history)) {
			foreach ($this->history as $m) {
				if (!is_array($m))
					continue;
				$role = isset($m['role']) ? $m['role'] : null;
				if ($role === 'system' || !$role)
					continue;

				$content = isset($m['content']) ? $m['content'] : '';
				$out[] = [
				    'role' => $role,
				    'content' => is_string($content) ? $content : $this->flatten_message_content($content),
				];
			}
			return $out;
		}


		if (isset($this->raw['output']) && is_array($this->raw['output'])) {
			foreach ($this->raw['output'] as $item) {
				if (isset($item['type']) && $item['type'] === 'message') {
					$msg = isset($item['message']) ? $item['message'] : null;
					if (is_array($msg)) {
						$role = isset($msg['role']) ? $msg['role'] : 'assistant';
						if ($role === 'system')
							continue;
						$content = isset($msg['content']) ? $msg['content'] : '';
						$out[] = [
						    'role' => $role,
						    'content' => is_string($content) ? $content : $this->flatten_message_content($content),
						];
					}
				}
			}
		}
		return $out;
	}

	private function flatten_message_content($content): string {
		if (is_string($content))
			return $content;
		$buf = '';
		if (is_array($content)) {
			foreach ($content as $block) {
				if (is_array($block)) {
					if (isset($block['text'])) {
						$buf .= is_string($block['text']) ? $block['text'] : json_encode($block['text'], JSON_UNESCAPED_UNICODE);
					} elseif (isset($block['content'])) {
						$buf .= is_string($block['content']) ? $block['content'] : json_encode($block['content'], JSON_UNESCAPED_UNICODE);
					}
				} elseif (is_string($block)) {
					$buf .= $block;
				}
			}
		}
		return $buf;
	}

	private function extract_message_from_output_item(array $item): ?array {
		if (isset($item['message']) && is_array($item['message'])) {
			return $item['message'];
		}
		if (isset($item['content'])) {
			return [
			    'role' => isset($item['role']) ? $item['role'] : 'assistant',
			    'content' => $item['content'],
			];
		}
		return null;
	}
}
