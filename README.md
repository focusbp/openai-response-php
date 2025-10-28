# OpenAI Response PHP

A lightweight PHP library that wraps the OpenAI Responses API to provide:

- **Vector Store integration** (sync local text files into an OpenAI Vector Store and use them as context)
- **Function Calling support** (tool invocation with typed arguments)

This library is intended to be easy to drop into a simple PHP app, even on older environments.

---

## Features

### 🧠 Vector Store Integration
- Syncs a local directory of text files into an OpenAI Vector Store.
- Lets the model answer questions using that knowledge.

### 🔧 Function Calling
- Define tools (functions) in PHP and expose them to the model.
- The model can call your tools with structured arguments.
- Supports dependency injection via a controller object.

### 💬 Conversation Recording
- Conversation history (messages) can be persisted via `Recorder` implementations:
  - `SessionRecorder` (stores in `$_SESSION`)
  - `FileRecorder` (stores in local JSON files)

### 📡 Status Polling
- Includes a simple async-style status polling example using `status_poll.php` to report "what the AI is doing right now" to the frontend.

---

## Requirements

- **PHP**: 7.3+ (no typed properties required)
- **Extensions**: `cURL`, `json`
- **Web server**: Any server that can run PHP (Apache, nginx + PHP-FPM, etc.)
- **OpenAI API Key**

---

## Quick Start

This is the fastest way to see it working in a browser.

1. **Set your API key**

   Open `tests/index.php` and set your OpenAI API key:

   ```php
   $apikey = 'sk-...';
   ```

2. **Deploy**

   Upload the entire `tests/` directory (and the project library) to a PHP-capable web server.

3. **Access the demo UI**

   Open `index.php` in your browser.  
   The sample UI can answer:
   - questions about upcoming events (from the Vector Store)
   - weather questions (via Function Calling)

---

## Vector Store Usage

### 1. Add source files
Put plain text files (or other supported text content) into the `vector_store/` directory.  
For example:

```text
vector_store/
  events.txt
  faq.txt
  company_info.txt
```

These files act as your "knowledge base."

### 2. Sync with OpenAI
From the sample UI (the test app in `tests/`), click:

**"Sync Vector Store"**

When you click that:
- The library will upload/sync the contents of `vector_store/` to the configured Vector Store in OpenAI.
- The Vector Store will then be used to ground answers.

If a Vector Store does not exist yet, the library can create one.  
If it already exists, the library can update it.

---

## Function Calling

The library exposes a Function Calling interface so the model can decide to call your PHP functions as tools.

### 1. Create a tool

Create a class in the `function_tools/` directory that implements `\focusbp\OpenAIResponsePhp\FunctionTool`.

Example (simplified):

```php
class Weather implements \focusbp\OpenAIResponsePhp\FunctionTool {

    public function name(): string {
        return "weather";
    }

    public function description(): string {
        return "Returns a weather forecast.";
    }

    public function parameters(): array {
        return [
            'type' => 'object',
            'properties' => [
                'city' => [
                    'type' => 'string',
                    'description' => 'Name of the city to get the weather for (e.g. "Tokyo", "Osaka")',
                ],
            ],
            'required' => ['city'],
            'additionalProperties' => false,
        ];
    }

    public function execute(\focusbp\OpenAIResponsePhp\Controller $ctl, array $arguments) {
        $city = isset($arguments['city']) ? (string)$arguments['city'] : '';

        if ($city === '') {
            return [
                'ok'    => false,
                'error' => 'Missing required argument "city".',
            ];
        }

        // Demo implementation
        return [
            'ok'       => true,
            'city'     => $city,
            'forecast' => 'Sunny',
        ];
    }
}
```

### 2. Dependency injection via Controller

If your tool needs shared services like:
- database connections
- HTTP clients
- config

…you can create your own class that **extends** `\focusbp\OpenAIResponsePhp\Controller` and put those dependencies there.

Then, when you instantiate the `OpenAI` client, pass that controller instance into the constructor.

Inside `execute()`, you’ll receive that same `$ctl` instance, so you can do things like `$ctl->db->query(...)` or `$ctl->weatherApi->fetch(...)`.

### 3. Auto-loading

Any class you put in `function_tools/` that implements `FunctionTool` will be auto-discovered and made available to the model.

---

## Conversation & Status

### Message history

The library keeps a running conversation history (system / user / assistant / tool messages).  
This can be persisted using a `Recorder`:

- `SessionRecorder` keeps messages in `$_SESSION`.
- `FileRecorder` can write JSON logs to disk.

Both provide:
- `read()`
- `write()`
- `append()`

### Status polling

The sample UI calls `status_poll.php` on an interval and displays a short status string (e.g. “fetching vector store…”).  
This status string is stored via a `StatusManager` implementation.

Example implementation:
- `SessionStatusManager` stores a `_status_msg` value in `$_SESSION`.

---

## Logging

All request/response logs and debug output can be written to the `log/` directory.

If you’re debugging or auditing model behavior:
- Check the generated files in `log/`.
- You can also wire up a `FileRecorder` so you have a full transcript.


---

## Project Structure (reference)

Below is a typical layout to help you navigate:

```text
/ (project root)
├─ src/
│  ├─ OpenAI.php                # Main wrapper class
│  ├─ Controller.php            # Base controller for dependency injection
│  ├─ Recorder.php              # Interface for saving conversation history
│  ├─ SessionRecorder.php       # Recorder using $_SESSION
│  ├─ FileRecorder.php          # Recorder using local JSON files
│  ├─ StatusManager.php         # Interface for run status tracking
│  ├─ SessionStatusManager.php  # Session-based StatusManager
│  └─ ... other core classes ...
│
├─ function_tools/
│  ├─ Weather.php               # Example FunctionTool implementation
│  └─ ... your tools ...
│
├─ vector_store/
│  └─ *.txt                     # Knowledge base files to sync
│
├─ log/
│  └─ *.log                     # Logs and conversation transcripts
│
├─ tests/
│  ├─ index.php                 # Browser demo UI
│  ├─ status_poll.php           # Polling endpoint for status
│  └─ style.css                 # Simple chat UI styling
│
└─ README.md
```

---

## Demo Flow (What the sample UI does)

1. You open `tests/index.php` in your browser.
2. You see a chat-like interface.
3. You ask something like:
   - “What upcoming events are there?”
   - “What’s the weather tomorrow in Tokyo?”
4. Under the hood:
   - For event questions, it retrieves knowledge from the synced Vector Store.
   - For weather questions, it calls the `Weather` tool via Function Calling.
5. The UI polls `status_poll.php` so you can see status text like “Start AI Processing...” or other internal messages.

---

## Security Notes

- Do not expose your API key publicly.  
  `tests/index.php` is for local or protected testing.
- Vector Store content is sent to OpenAI.  
  Do not sync files that contain secrets, PII, or anything you’re not comfortable sending to an API.
- Logs may contain both user input and model output.

---

## License

Add your preferred license here (MIT, Apache-2.0, etc.).

---

## Contributing

Pull requests and issues are welcome.  
If you add new tools or storage backends (e.g. RedisRecorder, DatabaseStatusManager), please include minimal usage docs in your PR so others can learn from it.
