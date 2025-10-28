<?php

namespace focusbp\OpenAIResponsePhp;

/**
 * SessionRecorder
 *
 * Implementation that stores and reads conversation history in
 * $_SESSION[$this->session_name][$this->sessionKey].
 */
class SessionRecorder implements Recorder {

    private $session_name;
    private $sessionKey = '_openai_messages';

    /**
     * @param string $session_name Session identifier used as the $_SESSION key
     */
    public function __construct(string $session_name) {
        $this->session_name = $session_name;
    }

    /** @inheritdoc */
    public function read(): array {
        $ret = $_SESSION[$this->session_name][$this->sessionKey];
        if (!empty($ret)) {
            return $ret;
        }
        return [];
    }

    /** @inheritdoc */
    public function write(array $messages): void {
        // Store messages with clean, zero-based numeric indexes
        $_SESSION[$this->session_name][$this->sessionKey] = array_values($messages);
    }

    /** @inheritdoc */
    public function append(string $role, $content): void {
        $msgs = $this->read();
        $msgs[] = [
            'role'    => $role,
            'content' => $content,
        ];
        $this->write($msgs);
    }

}
