#!/usr/bin/env bash
CONFIG_FILE="config.sh"
INSTALLER_PATH="$( cd "$( dirname "${BASH_SOURCE[0]}" )" >/dev/null 2>&1 && pwd )"

cd $INSTALLER_PATH

# Get/confirm the SENDY_PATH from the user
function get_sendy_path() 
{
	echo "Enter the path to your Sendy installation [/var/www/sendy/]"
	read SENDY_PATH
	if [ -z "${SENDY_PATH}" ]; then
		SENDY_PATH="/var/www/sendy/"
	fi
	echo "Sendy Path: ${SENDY_PATH}"
	echo "Is this correct? (y/[n])"
	read CONFIRM_INSTALL_PATH
	case "$CONFIRM_INSTALL_PATH" in
    		[yY][eE][sS]|[yY]) 
			echo "Saving config file..."
        		;;
    		*)
        		get_sendy_path
        		;;
	esac
}

# Save the SENDY_PATH to a config file
function save_config()
{
	if [ "${SENDY_PATH: -1}" != "/" ]; then
		SENDY_PATH="${SENDY_PATH}/"
	fi
	echo "SENDY_PATH=\"${SENDY_PATH}\"" > $CONFIG_FILE
}

# Set variables for the config and make sure their regularized
function set_vars()
{
        if [ "${SENDY_PATH: -1}" != "/" ]; then
                SENDY_PATH="${SENDY_PATH}/"
        fi
}

# Make sure that the supplied SENDY_PATH is a Sendy installation
function test_valid_sendy_path()
{
	TEST_PATH=$1
	if [ "${TEST_PATH: -1}" != "/" ]; then
			TEST_PATH="${TEST_PATH}/"
	fi

	if [ ! -f "${TEST_PATH}index.php" ]; then
		echo "${TEST_PATH} is not a valid path to a Sendy installation"
		exit
	else
		echo "Path ${TEST_PATH} is valid"
	fi
}

# Get/set config variables
if [ "$1" ]; then
	test_valid_sendy_path $1
	SENDY_PATH=$1
	save_config
elif [ ! -f "$CONFIG_FILE" ]; then
	get_sendy_path
	test_valid_sendy_path $SENDY_PATH
	save_config
else
	source $CONFIG_FILE
	test_valid_sendy_path $SENDY_PATH
fi

# Clean config variables
set_vars
echo "Installing to ${SENDY_PATH}..."

echo "Copying new files..."
cp -R src/* ${SENDY_PATH}

echo "Running php installer..."
php ${SENDY_PATH}sendy-improvements/install.php

echo "Done!"
