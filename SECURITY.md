# Security Policy

## Supported versions

This project is pre-1.0. Only the latest released version receives fixes.

## Reporting a vulnerability

Please report security issues privately rather than opening a public issue.

Use GitHub's [private vulnerability reporting](https://github.com/rubicon/diviops-module-bridge/security/advisories/new) for this repository, or email dax@daxdavis.com.

Include what you can: affected version, WordPress and PHP versions, reproduction steps, and the impact you believe it has. A proof of concept helps but is not required.

You can expect an acknowledgement within a week. If a fix is warranted, the advisory and release will credit you unless you prefer otherwise.

## Scope

This plugin intercepts REST responses from DiviOps Agent and writes post content. Findings that are especially relevant:

- Capability or authentication checks that can be bypassed to read or write content the caller should not reach.
- Input reaching post content without correct escaping, or a write path that corrupts stored content.
- Path traversal or arbitrary file reads through the `module.json` discovery glob.

Vulnerabilities in DiviOps Agent, DiviOps Agent Pro, DiviFlash, or Divi itself belong to those projects. If a report involves this plugin's interaction with one of them, send it here and it will be routed appropriately.
