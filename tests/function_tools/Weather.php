<?php

class Weather implements \focusbp\OpenAIResponsePhp\FunctionTool{
	
    public function name(): string{
        return "weather";
    }

    /** Description */
    public function description(): string{
        return "Returns a weather forecast.";
    }

    /** Definition of the parameters field in the JSON Schema (assumed Draft-07) */
    public function parameters(): array{
        return [
            'type' => 'object',
            'properties' => [
                'city' => [
                    'type' => 'string',
                    'description' => 'Name of the city to get the weather for (e.g. "Tokyo", "Osaka", "Sapporo")',
                ],
            ],
            'required' => ['city'],
            'additionalProperties' => false,
        ];
    }

    /**
     * Main execution logic. Receives arguments from the model and returns the result.
     * The return value must be either a string or an array
     * (the array will be JSON-encoded and placed into tool_outputs).
     *
     * @param array $arguments
     * @return mixed string|array
     */
    public function execute(\focusbp\OpenAIResponsePhp\Controller $ctl, array $arguments){
        // Extract the "city" argument
        $city = isset($arguments['city']) ? (string)$arguments['city'] : '';

        if ($city === '') {
            return [
                'ok'    => false,
                'error' => 'Missing required argument "city".',
            ];
        }

        // Dummy implementation: always "Sunny"
        return [
            'ok'       => true,
            'city'     => $city,
            'forecast' => 'Sunny',
        ];
    }
}
