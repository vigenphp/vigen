# AI Providers

| Provider | `VIGEN_AI_PROVIDER` | Required variables |
|---|---|---|
| Ollama | `ollama` | `OLLAMA_HOST` |
| OpenAI | `openai` | `OPENAI_API_KEY`, `OPENAI_MODEL` |
| Claude | `claude` | `ANTHROPIC_API_KEY`, `ANTHROPIC_MODEL` |
| Gemini | `gemini` | `GEMINI_API_KEY`, `GEMINI_MODEL` |

Switching providers only requires changing `.env` - application code never references a specific vendor.
