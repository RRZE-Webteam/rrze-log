/* eslint-disable no-console */
'use strict';

var fs = require('fs');
var path = require('path');

function readJson(filePath) {
	var raw = fs.readFileSync(filePath, 'utf8');
	return JSON.parse(raw);
}

function writeJson(filePath, obj) {
	var out = JSON.stringify(obj, null, 2) + '\n';
	fs.writeFileSync(filePath, out, 'utf8');
}

function parseSemver(version) {
	var m = version.match(/^(\d+)\.(\d+)\.(\d+)(?:-([0-9A-Za-z.-]+))?$/);
	if (!m) {
		throw new Error('Invalid semver: ' + version);
	}

	return {
		major: parseInt(m[1], 10),
		minor: parseInt(m[2], 10),
		patch: parseInt(m[3], 10),
		prerelease: m[4] || ''
	};
}

function formatSemver(v) {
	var base = String(v.major) + '.' + String(v.minor) + '.' + String(v.patch);
	if (v.prerelease) {
		return base + '-' + v.prerelease;
	}
	return base;
}

function bumpDev(version) {
	var v = parseSemver(version);

	if (!v.prerelease) {
		v.prerelease = '1';
		return formatSemver(v);
	}

	var m = v.prerelease.match(/^(\d+)$/);
	if (!m) {
		// Fremde prerelease-Labels: auf 1 "umschalten"
		v.prerelease = '1';
		return formatSemver(v);
	}

	var n = parseInt(m[1], 10);
	v.prerelease = String(n + 1);

	return formatSemver(v);
}

function bumpProd(version) {
	var v = parseSemver(version);

	// prerelease abschneiden, dann patch erhöhen
	v.prerelease = '';
	v.patch = v.patch + 1;

	return formatSemver(v);
}

function replaceInFile(filePath, replacer) {
	var content = fs.readFileSync(filePath, 'utf8');
	var updated = replacer(content);

	if (updated !== content) {
		fs.writeFileSync(filePath, updated, 'utf8');
	}
}

function setStyleCssVersion(pluginRoot, newVersion) {
	var filePath = path.join(pluginRoot, 'build/admin.css');
	replaceInFile(filePath, function (content) {
		return content.replace(
			/^(Version:\s*)(.+)$/m,
			function (match, p1) {
				return p1 + newVersion;
			}
		);
	});
}

function setReadmeTxtVersion(pluginRoot, newVersion) {
	var filePath = path.join(pluginRoot, 'readme.txt');

	if (!fs.existsSync(filePath)) {
		return;
	}

	replaceInFile(filePath, function (content) {
		// üblich im WP-readme: Stable tag
		content = content.replace(
			/^(Stable tag:\s*)(.+)$/m,
			function (match, p1) {
				return p1 + newVersion;
			}
		);

		// optional: Version: Zeile, falls du sie führst
		content = content.replace(
			/^(Version:\s*)(.+)$/m,
			function (match, p1) {
				return p1 + newVersion;
			}
		);

		return content;
	});
}

function setPluginVersion(pluginRoot, newVersion) {
	var filePath = path.join(pluginRoot, 'rrze-log.php');

	if (!fs.existsSync(filePath)) {
		return;
	}

	replaceInFile(filePath, function (content) {
		
		// Version suchen und aendern
		content = content.replace(
			/^(Version:\s*)(.+)$/m,
			function (match, p1) {
				return p1 + newVersion;
			}
		);

		return content;
	});
}


function main() {
	var mode = process.argv[2];
	if (mode !== 'dev' && mode !== 'prod') {
		console.error('Usage: node scripts/bump-version.js dev|prod');
		process.exit(1);
	}

	var pluginRoot = process.cwd();
	var packagePath = path.join(pluginRoot, 'package.json');
	var pkg = readJson(packagePath);

	if (!pkg.version || typeof pkg.version !== 'string') {
		throw new Error('package.json has no valid version');
	}

	var current = pkg.version;
	var next = mode === 'prod' ? bumpProd(current) : bumpDev(current);

	pkg.version = next;
	writeJson(packagePath, pkg);

	// setStyleCssVersion(pluginRoot, next);
	setReadmeTxtVersion(pluginRoot, next);
	setPluginVersion(pluginRoot, next);

	console.log('Version bumped: ' + current + ' -> ' + next);
}

main();
