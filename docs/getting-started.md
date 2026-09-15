# Getting Started

1. `composer require vigenphp/vigen` - creates `./vigen` in your project root (approve the plugin prompt, or see `installation.md`).
2. Run `php vigen init` and answer three questions:
   - **Choose chat preferred:** CLI or GUI
   - **Which default AI Provider:** Ollama (Offline), OpenAI, Claude, or Gemini
   - **Which model to use:** a curated list for the provider you picked
3. Vigen writes your choices to `.env` and scaffolds a new project (`app/`, `routes/`, `database/`, `config/`).
4. Start building:
   - CLI: `php vigen chat "Create a user management system"`
   - GUI: `php vigen serve`, then open the URL it prints

No manual file creation required either way - Vigen analyzes your project, plans the change, generates the files, and validates the result.
