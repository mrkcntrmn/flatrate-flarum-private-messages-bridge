const path = require('path');
const config = require('flarum-webpack-config');

const base = config();

module.exports = {
  ...base,
  entry: {
    ...base.entry,
    'forum-f2': path.resolve(__dirname, 'forum-f2.js'),
  },
};
