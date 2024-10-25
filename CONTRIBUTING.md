# Contributing to Dataphyre

Thank you for your interest in contributing to **Dataphyre**! We value your efforts to help improve this project, whether through code, documentation, bug reports, feature requests, or other means. This guide will help you get started with contributing.

---

## Table of Contents
1. [Code of Conduct](#code-of-conduct)
2. [Getting Started](#getting-started)
3. [How to Contribute](#how-to-contribute)
   - [Reporting Issues](#reporting-issues)
   - [Suggesting Features](#suggesting-features)
   - [Improving Documentation](#improving-documentation)
   - [Submitting Code Changes](#submitting-code-changes)
4. [Style Guides](#style-guides)
   - [Coding Style](#coding-style)
   - [Commit Messages](#commit-messages)
5. [License](#license)

---

## Code of Conduct

Please note that all contributors are expected to adhere to the [Code of Conduct](CODE_OF_CONDUCT.md). We strive to create a welcoming, inclusive, and harassment-free environment for everyone.

---

## Getting Started

### 1. Fork and Clone the Repository
To start contributing:
- Fork the Dataphyre repository to your GitHub account.
- Clone the forked repository to your local machine:
  ```bash
  git clone https://github.com/jeremie5/Dataphyre.git
  cd Dataphyre
  ```

### 2. Set Up Dependencies
Ensure you have the prerequisites installed, including PHP (>= 8.1) and Composer. Run:
```bash
composer install
```

### 3. Test Your Setup
Run the existing test suite to make sure everything is working. (Details on test commands can be found in the README or specific module documentation.)

---

## How to Contribute

### Reporting Issues

When reporting bugs, please include:
- A clear and descriptive title.
- Steps to reproduce the issue.
- Expected behavior vs. actual behavior.
- Any relevant logs, screenshots, or error messages.

### Suggesting Features

If you have ideas to make Dataphyre better, feel free to suggest features! Please include:
- A clear description of the proposed feature.
- How it benefits the framework and potential use cases.
- Any examples or context to support your suggestion.

### Improving Documentation

Well-documented code is essential to the success of Dataphyre. Contributions to documentation are always welcome. If you spot errors, inconsistencies, or areas for improvement, please help us improve clarity by submitting a pull request.

### Submitting Code Changes

1. Create a new branch for your work:
   ```bash
   git checkout -b feature/your-feature-name
   ```

2. Make changes in your branch, and include tests for any new functionality.

3. Commit your changes (following the [Commit Messages](#commit-messages) guide below).

4. Push your branch to GitHub:
   ```bash
   git push origin feature/your-feature-name
   ```

5. Open a Pull Request (PR) from your branch to the main branch in the Dataphyre repository. Please provide a detailed description of the changes in the PR.

---

## Style Guides

### Coding Style

- Follow existing project coding standards.
- Use meaningful variable and function names.
- Keep functions short and focused, aiming for single-responsibility.
- Add comments where necessary for clarity.

### Commit Messages

- **Format**: Use the format `Type: Description`.
- **Types**:
  - `feat`: Introduce a new feature
  - `fix`: Fix a bug
  - `docs`: Changes to documentation
  - `style`: Code style changes (formatting, missing semicolons, etc.)
  - `refactor`: Refactoring code without changing functionality
  - `test`: Adding or modifying tests

Example commit message:
```bash
feat: add async task scheduling to Async module
```

---

## License

By contributing, you agree that your contributions will be licensed under the same license as the project. For more details, refer to the [LICENSE](LICENSE.md) file in this repository.

---

Thank you for contributing to Dataphyre! We’re excited to work together to build a high-quality, scalable PHP framework.
