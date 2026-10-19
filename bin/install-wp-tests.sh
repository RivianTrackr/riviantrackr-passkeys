#!/usr/bin/env bash
# Installs WordPress and its PHPUnit test library for the integration suite.
#
#   bin/install-wp-tests.sh <db-name> <db-user> <db-pass> [db-host] [wp-version] [skip-database-creation]
#
# Based on the script WP-CLI scaffolds for plugins. Defaults: db-host
# localhost, wp-version latest. Override the install locations with
# WP_TESTS_DIR (default /tmp/wordpress-tests-lib) and WP_CORE_DIR
# (default /tmp/wordpress). Needs curl, tar, svn and the mysql client.
#
# Where wordpress.org and its Subversion server are unreachable, set
# WP_FROM_GITHUB=true: WordPress core (src/) and the test library then come
# from a shallow git clone of github.com/WordPress/wordpress-develop at the
# matching tag, and "latest" is resolved from that repository's tags.

set -euo pipefail

if [ $# -lt 3 ]; then
	echo "usage: $0 <db-name> <db-user> <db-pass> [db-host] [wp-version] [skip-database-creation]" >&2
	exit 1
fi

DB_NAME=$1
DB_USER=$2
DB_PASS=$3
DB_HOST=${4-localhost}
WP_VERSION=${5-latest}
SKIP_DB_CREATE=${6-false}

TMPDIR=${TMPDIR-/tmp}
TMPDIR=$(echo "$TMPDIR" | sed -e "s/\/$//")
WP_TESTS_DIR=${WP_TESTS_DIR-$TMPDIR/wordpress-tests-lib}
WP_CORE_DIR=${WP_CORE_DIR-$TMPDIR/wordpress}
WP_FROM_GITHUB=${WP_FROM_GITHUB-false}
WP_DEVELOP_REPO=https://github.com/WordPress/wordpress-develop.git

download() {
	curl -fsSL "$1" -o "$2"
}

# Highest stable x.y[.z] tag of wordpress-develop.
latest_github_tag() {
	git ls-remote --tags "$WP_DEVELOP_REPO" | grep -oE 'refs/tags/[0-9]+\.[0-9]+(\.[0-9]+)?$' | sed 's#refs/tags/##' | sort -V | tail -1
}

install_from_github() {
	local TAG=$1
	local CLONE="$TMPDIR/wordpress-develop-$TAG"
	if [ ! -d "$CLONE/src" ]; then
		rm -rf "$CLONE"
		git clone --quiet --depth 1 --branch "$TAG" "$WP_DEVELOP_REPO" "$CLONE"
	fi
	if [ ! -d "$WP_CORE_DIR" ]; then
		mkdir -p "$WP_CORE_DIR"
		cp -R "$CLONE/src/." "$WP_CORE_DIR/"
	fi
	if [ ! -d "$WP_TESTS_DIR/includes" ]; then
		mkdir -p "$WP_TESTS_DIR"
		cp -R "$CLONE/tests/phpunit/includes" "$WP_TESTS_DIR/includes"
		cp -R "$CLONE/tests/phpunit/data" "$WP_TESTS_DIR/data"
	fi
	if [ ! -f "$WP_TESTS_DIR/wp-tests-config.php" ]; then
		cp "$CLONE/wp-tests-config-sample.php" "$WP_TESTS_DIR/wp-tests-config.php"
		configure_tests
	fi
}

configure_tests() {
	local ioption='-i'
	if [[ $(uname -s) == 'Darwin' ]]; then
		ioption='-i.bak'
	fi
	local WP_CORE_DIR_ESCAPED
	WP_CORE_DIR_ESCAPED=$(echo "$WP_CORE_DIR" | sed "s/\//\\\\\//g")
	sed $ioption "s/dirname( __FILE__ ) . '\/src\/'/'$WP_CORE_DIR_ESCAPED\/'/" "$WP_TESTS_DIR/wp-tests-config.php"
	sed $ioption "s:__DIR__ . '/src/':'$WP_CORE_DIR/':" "$WP_TESTS_DIR/wp-tests-config.php"
	sed $ioption "s/youremptytestdbnamehere/$DB_NAME/" "$WP_TESTS_DIR/wp-tests-config.php"
	sed $ioption "s/yourusernamehere/$DB_USER/" "$WP_TESTS_DIR/wp-tests-config.php"
	sed $ioption "s/yourpasswordhere/$DB_PASS/" "$WP_TESTS_DIR/wp-tests-config.php"
	sed $ioption "s|localhost|${DB_HOST}|" "$WP_TESTS_DIR/wp-tests-config.php"
}

if [ "$WP_FROM_GITHUB" = "true" ]; then
	if [[ $WP_VERSION == 'latest' ]]; then
		WP_VERSION=$(latest_github_tag)
		if [[ -z "$WP_VERSION" ]]; then
			echo "Latest WordPress tag could not be found on GitHub" >&2
			exit 1
		fi
	fi
fi

# Resolve "latest" / "x.y" / "x.y.z" / "nightly" / "trunk" to a tag or branch.
if [[ $WP_VERSION =~ ^[0-9]+\.[0-9]+\-(beta|RC)[0-9]+$ ]]; then
	WP_BRANCH=${WP_VERSION%\-*}
	WP_TESTS_TAG="branches/$WP_BRANCH"
elif [[ $WP_VERSION =~ ^[0-9]+\.[0-9]+$ ]]; then
	WP_TESTS_TAG="branches/$WP_VERSION"
elif [[ $WP_VERSION =~ [0-9]+\.[0-9]+\.[0-9]+ ]]; then
	if [[ $WP_VERSION =~ [0-9]+\.[0-9]+\.[0] ]]; then
		WP_TESTS_TAG="tags/${WP_VERSION%??}"
	else
		WP_TESTS_TAG="tags/$WP_VERSION"
	fi
elif [[ $WP_VERSION == 'nightly' || $WP_VERSION == 'trunk' ]]; then
	WP_TESTS_TAG="trunk"
else
	download http://api.wordpress.org/core/version-check/1.7/ "$TMPDIR/wp-latest.json"
	LATEST_VERSION=$(grep -o '"version":"[^"]*' "$TMPDIR/wp-latest.json" | sed 's/"version":"//' | head -1)
	if [[ -z "$LATEST_VERSION" ]]; then
		echo "Latest WordPress version could not be found" >&2
		exit 1
	fi
	WP_TESTS_TAG="tags/$LATEST_VERSION"
fi

install_wp() {
	if [ -d "$WP_CORE_DIR" ]; then
		return
	fi
	mkdir -p "$WP_CORE_DIR"

	if [[ $WP_VERSION == 'nightly' || $WP_VERSION == 'trunk' ]]; then
		mkdir -p "$TMPDIR/wordpress-trunk"
		rm -rf "$TMPDIR/wordpress-trunk/*"
		svn export --quiet https://core.svn.wordpress.org/trunk "$TMPDIR/wordpress-trunk/wordpress"
		mv "$TMPDIR/wordpress-trunk/wordpress/"* "$WP_CORE_DIR"
	else
		if [ "$WP_VERSION" == 'latest' ]; then
			local ARCHIVE_NAME='latest'
		elif [[ $WP_VERSION =~ [0-9]+\.[0-9]+ ]]; then
			local ARCHIVE_NAME="wordpress-${WP_TESTS_TAG#tags/}"
		else
			local ARCHIVE_NAME="wordpress-$WP_VERSION"
		fi
		download "https://wordpress.org/${ARCHIVE_NAME}.tar.gz" "$TMPDIR/wordpress.tar.gz"
		tar --strip-components=1 -zxmf "$TMPDIR/wordpress.tar.gz" -C "$WP_CORE_DIR"
	fi

	download https://raw.githubusercontent.com/markoheijnen/wp-mysqli/master/db.php "$WP_CORE_DIR/wp-content/db.php"
}

install_test_suite() {
	local ioption='-i'
	if [[ $(uname -s) == 'Darwin' ]]; then
		ioption='-i.bak'
	fi

	if [ ! -d "$WP_TESTS_DIR" ]; then
		mkdir -p "$WP_TESTS_DIR"
		rm -rf "$WP_TESTS_DIR/"{includes,data}
		svn export --quiet --ignore-externals "https://develop.svn.wordpress.org/${WP_TESTS_TAG}/tests/phpunit/includes/" "$WP_TESTS_DIR/includes"
		svn export --quiet --ignore-externals "https://develop.svn.wordpress.org/${WP_TESTS_TAG}/tests/phpunit/data/" "$WP_TESTS_DIR/data"
	fi

	if [ ! -f "$WP_TESTS_DIR/wp-tests-config.php" ]; then
		download "https://develop.svn.wordpress.org/${WP_TESTS_TAG}/wp-tests-config-sample.php" "$WP_TESTS_DIR/wp-tests-config.php"
		configure_tests
	fi
}

install_db() {
	if [ "${SKIP_DB_CREATE}" = "true" ]; then
		return 0
	fi

	local PARTS=(${DB_HOST//\:/ })
	local DB_HOSTNAME=${PARTS[0]}
	local DB_SOCK_OR_PORT=${PARTS[1]-}
	local EXTRA=""

	if [ -n "$DB_HOSTNAME" ]; then
		if [[ "$DB_SOCK_OR_PORT" =~ ^[0-9]+$ ]]; then
			EXTRA=" --host=$DB_HOSTNAME --port=$DB_SOCK_OR_PORT --protocol=tcp"
		elif [ -n "$DB_SOCK_OR_PORT" ]; then
			EXTRA=" --socket=$DB_SOCK_OR_PORT"
		elif [ -n "$DB_HOSTNAME" ]; then
			EXTRA=" --host=$DB_HOSTNAME --protocol=tcp"
		fi
	fi

	# shellcheck disable=SC2086
	mysql -e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\`" --user="$DB_USER" --password="$DB_PASS"$EXTRA
}

if [ "$WP_FROM_GITHUB" = "true" ]; then
	install_from_github "$WP_VERSION"
else
	install_wp
	install_test_suite
fi
install_db
echo "WordPress ${WP_TESTS_TAG#tags/} in $WP_CORE_DIR, test library in $WP_TESTS_DIR, database $DB_NAME on $DB_HOST."
