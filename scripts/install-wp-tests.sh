#!/usr/bin/env bash
#
# Create the dedicated PHPUnit test database for this plugin.
#
# The WordPress core test library itself comes from the Composer package
# wp-phpunit/wp-phpunit, so this script only provisions the one thing
# Composer can't: a clean database. Run inside the ddev web container.
#
#   ddev exec -d /var/www/html/wp-content/plugins/linkpurpose-wp composer install-test-suite
#
# The database name is separate from Editoria11y's (`wordpress_test`) so the
# two suites can run back-to-back without clobbering each other's tables.

if [ $# -lt 3 ]; then
	echo "usage: $0 <db-name> <db-user> <db-pass> [db-host]"
	exit 1
fi

DB_NAME=$1
DB_USER=$2
DB_PASS=$3
DB_HOST=${4-db}

set -e

mysqladmin --user="$DB_USER" --password="$DB_PASS" --host="$DB_HOST" drop -f "$DB_NAME" 2>/dev/null || true
mysqladmin --user="$DB_USER" --password="$DB_PASS" --host="$DB_HOST" create "$DB_NAME"

echo "Test database created: $DB_NAME on $DB_HOST"
echo "WP test library is provided by vendor/wp-phpunit/wp-phpunit — run \`composer install\` if you haven't yet."
