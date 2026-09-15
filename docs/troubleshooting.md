# Troubleshooting

**Vigen can't reach my provider.** Check the relevant `.env` variable (`OLLAMA_HOST`, `OPENAI_API_KEY`, `ANTHROPIC_API_KEY`, or `GEMINI_API_KEY`) and that the provider is configured (`AIProviderInterface::isConfigured()`).

**Validation keeps failing.** Vigen retries once through its self-correction loop. Persistent failures are printed with the underlying error so you can fix them manually.
