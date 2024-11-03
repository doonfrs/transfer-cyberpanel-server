#!/bin/bash

# Check if config.ini exists
if [ ! -f "config.ini" ]; then
    echo "Error: config.ini not found."
    echo "Please copy config.ini.template to config.ini and fill in the remote server information."
    echo "Then run the script again."
    exit 1
fi


php migrate-script/00-migrate-info.php

echo "if You are sure about that, type 'yes' and press enter: ";
read -r answer
if [ "$answer" != "yes" ]; then
    echo "Aborting...";
    exit 1;
fi

echo "Running migrations..."    
echo "Migrating websites..."
php migrate-script/01-migrate-websites.php && \
echo "Migrating emails..." && \
php migrate-script/02-migrate-emails.php && \
echo "Migrating websites data..." && \
php migrate-script/03-migrate-websites-data.php && \
echo "Migrating vmail..." && \
php migrate-script/04-migrate-websites-vmail.php && \
echo "All migrations completed." 
