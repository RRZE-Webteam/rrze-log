#!/usr/bin/env node

'use strict';

var fs = require('fs');
var path = require('path');
var esbuild = require('esbuild');
var sass = require('sass');

function ensureDir(dirPath) {
    if (!fs.existsSync(dirPath)) {
        fs.mkdirSync(dirPath, { recursive: true });
    }
}

function parseArgs(argv) {
    var mode = 'dev';
    var watch = false;
    var i;

    for (i = 2; i < argv.length; i++) {
        if (argv[i] === 'dev' || argv[i] === 'prod') {
            mode = argv[i];
        } else if (argv[i] === '--watch') {
            watch = true;
        }
    }

    return { mode: mode, watch: watch };
}

function buildJs(mode, watch) {
    var isProd = mode === 'prod';

    return esbuild.build({
        entryPoints: [path.join('src', 'admin', 'admin.js')],
        bundle: true,
        sourcemap: isProd ? false : true,
        minify: isProd ? true : false,
        format: 'iife',
        target: ['es2018'],
        outfile: path.join('assets', 'js', 'rrze-log.js')
    }).then(function () {
        return true;
    });
}

function buildCss(mode) {
    var isProd = mode === 'prod';
    var outFile = path.join('assets', 'css', 'rrze-log.css');
    var result = sass.compile(path.join('src', 'admin', 'admin.scss'), {
        style: isProd ? 'compressed' : 'expanded',
        sourceMap: isProd ? false : true,
        sourceMapIncludeSources: true
    });

    fs.writeFileSync(outFile, result.css);

    if (!isProd && result.sourceMap) {
        fs.writeFileSync(outFile + '.map', JSON.stringify(result.sourceMap));
    }

    return true;
}

function runOnce(mode) {
    ensureDir(path.join('assets', 'js'));
    ensureDir(path.join('assets', 'css'));

    return buildJs(mode, false).then(function () {
        buildCss(mode);
        return true;
    });
}

function runWatch(mode) {
    ensureDir(path.join('assets', 'js'));
    ensureDir(path.join('assets', 'css'));

    var jsCtxPromise = esbuild.context({
        entryPoints: [path.join('src', 'admin', 'admin.js')],
        bundle: true,
        sourcemap: true,
        minify: false,
        format: 'iife',
        target: ['es2018'],
        outfile: path.join('assets', 'js', 'rrze-log.js')
    });

    return jsCtxPromise.then(function (ctx) {
        return ctx.watch().then(function () {
            sass.compileAsync(path.join('src', 'admin', 'admin.scss'), {
                style: 'expanded',
                sourceMap: true,
                sourceMapIncludeSources: true
            }).then(function (result) {
                var outFile = path.join('assets', 'css', 'rrze-log.css');
                fs.writeFileSync(outFile, result.css);
                fs.writeFileSync(outFile + '.map', JSON.stringify(result.sourceMap));

                sass.watch(path.join('src', 'admin', 'admin.scss'), function () {
                    sass.compileAsync(path.join('src', 'admin', 'admin.scss'), {
                        style: 'expanded',
                        sourceMap: true,
                        sourceMapIncludeSources: true
                    }).then(function (result2) {
                        fs.writeFileSync(outFile, result2.css);
                        fs.writeFileSync(outFile + '.map', JSON.stringify(result2.sourceMap));
                    }).catch(function (err) {
                        console.error(err);
                    });
                });

                console.log('[rrze-log] watching JS+SCSS...');
                return true;
            });
        });
    });
}

function main() {
    var args = parseArgs(process.argv);

    if (args.watch) {
        return runWatch('dev');
    }

    return runOnce(args.mode);
}

main().catch(function (err) {
    console.error(err);
    process.exit(1);
});


