<?php

namespace focusbp\OpenAIResponsePhp;

/**
 * SessionStatusManager
 *
 * StatusManager implementation that stores a status string in $_SESSION.
 *
 * The status is saved under:
 *   $_SESSION[$this->session_name]['_status_msg']
 *
 * Typical usage:
 * - set_status("fetching vector store...")
 * - get_status() => "fetching vector store..."
 *
 * This can be used to expose simple progress / state information
 * to the outside (e.g. frontend polling).
 */
class SessionStatusManager implements \focusbp\OpenAIResponsePhp\StatusManager {
	
    /** @var string Session key namespace */
	private $session_name;
	
    /**
     * @param string $session_name
     *        Identifier used to group data in $_SESSION.
     */
	public function __construct(string $session_name) {
		$this->session_name = $session_name;
	}
	
    /**
     * Save (or update) the current status string into $_SESSION.
     *
     * @param string $status Human-readable status text
     * @return void
     */
	public function set_status(string $status): void {
		$_SESSION[$this->session_name]['_status_msg'] = $status;
	}

    /**
     * Get the current status string from $_SESSION.
     *
     * Returns null if nothing is stored yet.
     *
     * @return string|null Current status text, or null if not set
     */
	public function get_status(): ?string {
		return $_SESSION[$this->session_name]['_status_msg'];
	}
}
