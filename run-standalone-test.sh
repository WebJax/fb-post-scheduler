#!/bin/bash
# Script til at køre Facebook Post Scheduler standalone test

# Skift til plugin-mappen
cd "$(dirname "$0")"

# Vis information om testen
echo "Running Facebook Post Scheduler standalone test..."
echo "Current directory: $(pwd)"

# Opret test-logs mappe hvis den ikke eksisterer
mkdir -p tests/test-logs

# Kør PHP-scriptet
php tests/standalone-test.php

# Pause så man kan se resultatet
read -p "Press enter to exit..."
