module.exports = {
  apps: [{
    name: 'mdt-bot',
    script: 'bot.js',
    cwd: __dirname,
    env: {
      NODE_ENV: 'production'
    },
    max_restarts: 10,
    restart_delay: 5000,
    watch: false,
    log_date_format: 'YYYY-MM-DD HH:mm:ss'
  }]
};
