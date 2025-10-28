<?php

namespace focusbp\OpenAIResponsePhp;

/**
 * Interface for Function Calling tools.
 */
interface FunctionTool {

	/**
	 * Tool name (alphanumeric and underscores recommended).
	 */
	public function name(): string;

	/**
	 * Description of what the tool does.
	 */
	public function description(): string;

	/**
	 * Returns the "parameters" schema used for OpenAI Function Calling.
	 *
	 * This should be an associative array representing a JSON Schema (Draft-07),
	 * describing the tool's arguments. The returned array will be sent to OpenAI
	 * as the `parameters` field of the tool definition.
	 *
	 * Example keys typically include: "type", "properties", and "required".
	 *
	 * @return array
	 */
	public function parameters(): array;

	/**
	 * Executes the actual tool logic.
	 *
	 * This method is called when the model has chosen this tool and provided arguments.
	 *
	 * @param \focusbp\OpenAIResponsePhp\Controller $ctl
	 *        A controller/container instance. This is used to access shared
	 *        application context such as database connections, configuration,
	 *        external clients, etc.
	 *
	 *        You may pass a subclass that extends Controller, so tool
	 *        implementations can rely on project-specific methods or services.
	 *
	 * @param array $arguments
	 *        The arguments sent by OpenAI. These correspond to the schema
	 *        defined in parameters(). After the model calls the tool, the
	 *        parsed argument values are provided here.
	 *
	 * @return string|array
	 *         The result of the tool call.
	 *
	 *         - If a string is returned, it will be used as-is.
	 *         - If an array is returned, it will be JSON-encoded and included
	 *           in tool_outputs.
	 */
	public function execute(\focusbp\OpenAIResponsePhp\Controller $ctl, array $arguments);
}
