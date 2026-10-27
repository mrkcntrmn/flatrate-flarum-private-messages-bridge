const path = require('path');
const config = require('flarum-webpack-config');

const base = config();

/**
 * F1 helper paths removed in F2 — rewrite before flarum-webpack-config v3
 * externals resolve `flarum/*` via flarum.reg.get.
 */
const F1_TO_F2_REQUEST = {
  'flarum/common/helpers/avatar': 'flarum/common/components/Avatar',
  'flarum/helpers/avatar': 'flarum/common/components/Avatar',
  'flarum/common/helpers/icon': 'flarum/common/components/Icon',
  'flarum/helpers/icon': 'flarum/common/components/Icon',
};

function remapFlarumRequest(request) {
  return Object.prototype.hasOwnProperty.call(F1_TO_F2_REQUEST, request)
    ? F1_TO_F2_REQUEST[request]
    : request;
}

function flarumRegistryExternal({ request }, callback) {
  let matches;
  if ((matches = /^flarum\/(.+)$/.exec(remapFlarumRequest(request)))) {
    return callback(null, `root flarum.reg.get('core', '${matches[1]}')`);
  }
  if ((matches = /^ext:([^/]+)\/(?:flarum-(?:ext-)?)?([^/]+)(?:\/(.+))?$/.exec(request))) {
    const namespace = `${matches[1]}-${matches[2]}`;
    const id = matches[3];
    return callback(null, `root flarum.reg.get('${namespace}', '${id}')`);
  }
  return callback();
}

module.exports = {
  ...base,
  entry: {
    ...base.entry,
    'forum-f2': path.resolve(__dirname, 'forum-f2.js'),
  },
  externals: [
    {
      jquery: 'jQuery',
    },
    flarumRegistryExternal,
  ],
  resolve: {
    ...base.resolve,
    alias: {
      ...(base.resolve && base.resolve.alias),
      ...F1_TO_F2_REQUEST,
    },
  },
};
