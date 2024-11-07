#!/bin/bash

php81="/usr/local/lsws/lsphp81/bin/php"

verbose=""

for arg in "$@"; do
  if [[ "$arg" == "-v" ]]; then
    verbose="-v"
  fi
done


if [ ! -x "$php81" ]; then
    echo "PHP 8.1 is not installed at $php81_path."
    echo "Please install PHP 8.1 or ensure it is available at the specified path."
    exit 1
fi


if ! type -P sshpass &> /dev/null
then
    echo "sshpass is not installed. Installing it now..."
    
    # Update package list and install sshpass
    sudo apt update
    sudo apt install -y sshpass

    echo "sshpass has been successfully installed."
fi

# Check if config.ini exists
if [ ! -f "config.ini" ]; then
    echo "Error: config.ini not found."
    echo "Please copy config.ini.template to config.ini and fill in the remote server information."
    echo "Then run the script again."
    exit 1
fi


php migrate-scripts/00-migrate-info.php

echo "if You are sure about that, type 'yes' and press enter: ";
read -r answer
if [ "$answer" != "yes" ]; then
    echo "Aborting...";
    exit 1;
fi

echo "Running migrations..."    
echo -e "**********Migrating websites**********\n\n" && \
$php81 migrate-scripts/01-migrate-websites.php $verbose && \
echo -e "**********Migrating emails**********\n\n" && \
$php81 migrate-scripts/02-migrate-emails.php $verbose && \
echo -e "**********Migrating databases**********\n\n" && \
$php81 migrate-scripts/03-migrate-websites-databases.php $verbose && \
echo -e "**********Migrating data**********\n\n" && \
$php81 migrate-scripts/04-migrate-websites-data.php $verbose && \
echo -e "**********Migrating vmail**********\n\n" && \
$php81 migrate-scripts/05-migrate-websites-vmail.php $verbose

