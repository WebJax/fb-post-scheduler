#!/bin/bash
# Script til at køre Facebook Post Scheduler test

# Skift til plugin-mappen
cd "$(dirname "$0")"

# Vis information om testen
echo "Running Facebook Post Scheduler test..."
echo "Current directory: $(pwd)"

# Kør PHP-scriptet
php tests/test-facebook-posts.php

# Pause så man kan se resultatet
read -p "Press enter to exit..."
