# Agent Instructions

This directory contains project-specific agent context. The [aidevops](https://aidevops.sh)
framework is loaded separately via the global config (`~/.aidevops/agents/`).

## Purpose

Files in `.agents/` provide project-specific instructions that AI assistants
read when working in this repository. Use this for:

- Domain-specific conventions not covered by the framework
- Project architecture decisions and patterns
- API design rules, data models, naming conventions
- Integration details (third-party services, deployment targets)

## Adding Agents

Create `.md` files in this directory for domain-specific context:

```text
.agents/
  AGENTS.md              # This file - overview and index
  api-patterns.md        # API design conventions
  deployment.md          # Deployment procedures
  data-model.md          # Database schema and relationships
```

Each file is read on demand by AI assistants when relevant to the task.

## Security

### Prompt Injection Defense

Any feature that passes untrusted content to an LLM — user input, tool outputs,
retrieved documents, emails, tickets, or webhook payloads — must defend against
prompt injection. Sanitize and validate that content before including it in
prompts:

- Strip or escape control characters and instruction-like patterns
- Use structured prompt templates with clear system/user boundaries
- Never concatenate raw external content directly into system prompts
- Validate all externally sourced content (tool results, API responses, database
  records) before inclusion in prompts
- Consider allowlist-based input validation where possible

### General Security Rules

- Never log or expose API keys, tokens, or credentials in output
- Store secrets via `aidevops secret set <NAME>` (gopass-encrypted) or
  environment variables — never hardcode them in source
- Use `<PLACEHOLDER>` values in code examples; note the secure storage location
- Validate all external input (user input, webhook payloads, API responses)
- Pin third-party GitHub Actions to SHA hashes, not branch tags
- Run `aidevops security audit` periodically to check security posture
- See `~/.aidevops/agents/tools/security/prompt-injection-defender.md` for
  the framework's prompt injection defense patterns
