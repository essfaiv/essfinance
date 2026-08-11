# EssFinance Technical Documentation

Documentation index for developers and contributors.

## Architecture & Design

- [**ARCHITECTURE.md**](ARCHITECTURE.md) - System architecture and design decisions
- [**UI-LAYOUT.md**](UI-LAYOUT.md) - User interface layout and components
- [**INTERFACE.png**](INTERFACE.png) - Visual layout mockup

## Implementation Details

- [**TECHNICAL.md**](TECHNICAL.md) - v0.2.0 Technical implementation details
- [**IMPLEMENTATION.md**](IMPLEMENTATION.md) - Implementation notes and patterns
- [**CODE-REFERENCE.md**](CODE-REFERENCE.md) - Code reference and API documentation

## Integration & Extension

- [**API.md**](API.md) - Public API and hooks for developers

## Quick Links

- [Main README](../README.md) - User-facing documentation
- [Changelog](../CHANGELOG.md) - Version history and updates
- [Roadmap](../ROADMAP.md) - Future plans and upcoming features

## For Plugin Developers

To extend EssFinance, start with:

1. **[ARCHITECTURE.md](ARCHITECTURE.md)** - Understand the system design
2. **[API.md](API.md)** - Learn available hooks and functions
3. **[CODE-REFERENCE.md**](CODE-REFERENCE.md) - Reference the codebase

## Database Schema

Standard WordPress post type structure:
- **post_type**: `essf_cashflow`
- **post_statuses**: `pending`, `paid`
- **meta_keys**: `_order_date`, `_entry_type`

See [ARCHITECTURE.md](ARCHITECTURE.md) for full schema details.
