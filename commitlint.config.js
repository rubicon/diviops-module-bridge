// Conventional Commits 1.0.0, restricted to the @commitlint/config-conventional
// type set. This config is the source of truth for commit and PR-title linting.
module.exports = {
  extends: ['@commitlint/config-conventional'],
  rules: {
    'type-enum': [
      2,
      'always',
      [
        'feat',
        'fix',
        'chore',
        'docs',
        'test',
        'ci',
        'refactor',
        'build',
        'perf',
        'revert',
        'style',
      ],
    ],
  },
};
