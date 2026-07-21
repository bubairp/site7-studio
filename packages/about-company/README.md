# About Company Pattern Package

An elegant Pattern composition designed to build a standard About section.

## Composition
This Pattern is composed of two primary sections:
1. **Hero Banner** (`hero-banner`)
2. **Team** (`team`)

## Installation
When this Pattern is installed via the Site7 Library, the system will automatically:
- Verify that both the `hero-banner` and `team` Section packages are installed.
- Install and enable any missing Section packages automatically.
- Register the `aboutCompany` Matrix block.

## Rendering
The Pattern is rendered by compiling the `template.twig` in sequence:
- Hero Banner receives `block.hero_banner` variables.
- Team receives `block.team` variables.
