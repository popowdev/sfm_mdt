
require('dotenv').config();
const { Client, GatewayIntentBits, Events, Partials, REST, Routes, SlashCommandBuilder, MessageFlags } = require('discord.js');
const express = require('express');
const mysql = require('mysql2/promise');

const BOT_TOKEN = process.env.BOT_TOKEN;
const GUILD_ID  = process.env.GUILD_ID;
const API_KEY   = process.env.API_KEY;
const PORT      = parseInt(process.env.PORT || '3100');

const pool = mysql.createPool({
    host: process.env.DB_HOST || 'localhost',
    database: process.env.DB_NAME || 'mdt_main',
    user: process.env.DB_USER || 'mdt_admin',
    password: process.env.DB_PASS || '',
    waitForConnections: true,
    connectionLimit: 10,
    charset: 'utf8mb4',
    enableKeepAlive: true,
    keepAliveInitialDelay: 0
});

const client = new Client({
    intents: [
        GatewayIntentBits.Guilds,
        GatewayIntentBits.GuildMembers,
        GatewayIntentBits.GuildPresences
    ]
});

let guild = null;

client.once(Events.ClientReady, async (c) => {
    console.log(`[BOT] Connecte en tant que ${c.user.tag}`);

    try {
        guild = await client.guilds.fetch(GUILD_ID);
        console.log(`[BOT] Guilde: ${guild.name} (${guild.memberCount} membres)`);

        await guild.members.fetch();
        console.log(`[BOT] ${guild.members.cache.size} membres charges en cache`);

        await syncRolesCache();
        await syncAllUserRoles();
        console.log('[BOT] Sync initiale terminee');
    } catch (err) {
        console.error('[BOT] Erreur init guilde:', err.message);
    }

    try {
        const commands = [
            new SlashCommandBuilder()
                .setName('resetmdp')
                .setDescription('Reinitialiser ton mot de passe RP MDT')
                .toJSON()
        ];
        const rest = new REST({ version: '10' }).setToken(BOT_TOKEN);
        await rest.put(Routes.applicationCommands(c.user.id), { body: [] });
        await rest.put(Routes.applicationGuildCommands(c.user.id, GUILD_ID), { body: commands });
        console.log('[BOT] Slash commands enregistrees');
    } catch (err) {
        console.error('[BOT] Erreur enregistrement slash commands:', err.message);
    }

    setInterval(async () => {
        try {
            if (guild) {
                await guild.members.fetch();
                await syncRolesCache();
                await syncAllUserRoles();
                console.log('[SYNC] Sync periodique terminee');
            }
        } catch (err) {
            console.error('[SYNC] Erreur:', err.message);
        }
    }, 30 * 60 * 1000);
});

client.on(Events.GuildMemberUpdate, async (oldMember, newMember) => {
    const oldSet = new Set(oldMember.roles.cache.keys());
    const newSet = new Set(newMember.roles.cache.keys());
    const changed = [...oldSet].some(r => !newSet.has(r)) || [...newSet].some(r => !oldSet.has(r));
    if (!changed) return;

    console.log(`[EVENT] Roles modifies pour ${newMember.user.tag} (${newMember.id})`);

    try {
        const conn = await pool.getConnection();
        try {
            const [rows] = await conn.query('SELECT id FROM users WHERE discord_id = ?', [newMember.id]);
            if (rows.length > 0) {
                const roleIds = [...newSet].filter(r => r !== GUILD_ID);
                await conn.query(
                    'UPDATE users SET discord_roles = ?, discord_nick = ?, discord_username = ? WHERE discord_id = ?',
                    [JSON.stringify(roleIds), newMember.nickname || null, newMember.user.username, newMember.id]
                );
                console.log(`[EVENT] User #${rows[0].id} mis a jour (${roleIds.length} roles)`);
            }
        } finally {
            conn.release();
        }
    } catch (err) {
        console.error('[EVENT] Erreur update roles:', err.message);
    }
});

client.on(Events.GuildMemberRemove, async (member) => {
    console.log(`[EVENT] ${member.user ? member.user.tag : member.id} a quitte le serveur`);
    try {
        const conn = await pool.getConnection();
        try {
            await conn.query(
                'UPDATE users SET discord_roles = JSON_ARRAY() WHERE discord_id = ?',
                [member.id]
            );
            console.log(`[EVENT] Roles vides pour ${member.id}`);
        } finally { conn.release(); }
    } catch (err) {
        console.error('[EVENT] Erreur GuildMemberRemove:', err.message);
    }
});

async function syncRolesCache() {
    if (!guild) return;
    const conn = await pool.getConnection();
    try {
        const roles = guild.roles.cache
            .filter(r => r.id !== GUILD_ID)
            .map(r => [r.id, r.name, '#' + r.color.toString(16).padStart(6, '0'), r.position]);

        if (roles.length === 0) return;

        await conn.query(
            'REPLACE INTO discord_roles_cache (role_id, name, color, position, updated_at) VALUES ?',
            [roles.map(r => [...r, new Date()])]
        );
        console.log(`[SYNC] ${roles.length} roles en cache`);
    } finally {
        conn.release();
    }
}

async function syncAllUserRoles() {
    if (!guild) return;
    const conn = await pool.getConnection();
    try {
        const [users] = await conn.query('SELECT id, discord_id FROM users WHERE discord_id IS NOT NULL');
        let updated = 0;

        for (const user of users) {
            const member = guild.members.cache.get(user.discord_id);
            if (member) {
                const roleIds = [...member.roles.cache.keys()].filter(r => r !== GUILD_ID);
                await conn.query(
                    'UPDATE users SET discord_roles = ?, discord_nick = ?, discord_username = ?, discord_avatar = ? WHERE id = ?',
                    [
                        JSON.stringify(roleIds),
                        member.nickname || null,
                        member.user.username,
                        member.user.displayAvatarURL({ size: 128 }),
                        user.id
                    ]
                );
                updated++;
            }
        }
        console.log(`[SYNC] ${updated}/${users.length} utilisateurs mis a jour`);
    } finally {
        conn.release();
    }
}

client.on(Events.InteractionCreate, async (interaction) => {
    if (!interaction.isChatInputCommand()) return;

    if (interaction.commandName === 'resetmdp') {
        const discordId = interaction.user.id;

        await interaction.deferReply({ flags: MessageFlags.Ephemeral });

        try {
            const [rows] = await pool.execute('SELECT id, username FROM users WHERE discord_id = ?', [discordId]);

            if (!rows.length) {
                await interaction.editReply({ content: '❌ Aucun compte RP MDT lie a ton Discord.\nConnecte-toi d\'abord sur https://exemple.tld/login puis lie ton Discord.' });
                return;
            }

            const username = rows[0].username;
            const userId = rows[0].id;

            const chars = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
            let newPassword = '';
            for (let i = 0; i < 8; i++) {
                newPassword += chars[Math.floor(Math.random() * chars.length)];
            }

            const bcrypt = require('bcrypt');
            const hash = await bcrypt.hash(newPassword, 10);

            await pool.execute('UPDATE users SET password_hash = ? WHERE id = ?', [hash, userId]);
            await pool.execute('DELETE FROM user_sessions WHERE user_id = ?', [userId]);

            try {
                await interaction.user.send(
                    `🔐 **RP MDT — Mot de passe reinitialise**\n\n` +
                    `📋 **Identifiant :** \`${username}\`\n` +
                    `🔑 **Nouveau mot de passe :** ||\`${newPassword}\`||\n\n` +
                    `🔗 Connecte-toi sur https://exemple.tld/login\n` +
                    `💡 *Clique sur le spoiler pour voir le mot de passe.*\n` +
                    `⚠️ *Change ton mot de passe apres ta premiere connexion.*`
                );
                await interaction.editReply({ content: '✅ Nouveau mot de passe envoye en DM !' });
            } catch (dmErr) {
                await interaction.editReply({
                    content: `⚠️ **Tes DMs sont bloques.** Voici tes identifiants :\n\n📋 **Identifiant :** \`${username}\`\n🔑 **Nouveau MDP :** ||\`${newPassword}\`||\n\n*Active tes DMs pour plus de securite.*`
                });
            }

            console.log(`[BOT] Reset MDP pour ${username} (Discord: ${discordId})`);
        } catch (err) {
            console.error('[BOT] Erreur reset MDP:', err.message, err.stack);
            await interaction.editReply({ content: '❌ **Erreur lors du reset.** Contacte un administrateur.' });
        }
    }
});

const app = express();
const http = require('http');
const httpServer = http.createServer(app);

let io;
try {
    const { Server } = require('socket.io');
    io = new Server(httpServer, {
        cors: { origin: ['https://exemple.tld', 'http://exemple.tld', 'http://localhost'], methods: ['GET', 'POST'] },
        path: '/dispatch-ws/'
    });

    io.use(async (socket, next) => {
        const token = (socket.handshake.auth && socket.handshake.auth.token)
            || (socket.handshake.query && socket.handshake.query.token)
            || '';
        if (!token) return next(new Error('Auth requise'));
        try {
            const conn = await pool.getConnection();
            try {
                const [rows] = await conn.query(
                    'SELECT u.id, u.discord_id FROM user_sessions s JOIN users u ON u.id = s.user_id WHERE s.token = ? AND s.expires_at > NOW() LIMIT 1',
                    [token]
                );
                if (!rows.length) return next(new Error('Token invalide'));
                socket.userId = rows[0].id;
                socket.discordId = rows[0].discord_id;
                next();
            } finally { conn.release(); }
        } catch (err) {
            console.error('[WS auth] Erreur:', err.message);
            next(new Error('Erreur auth'));
        }
    });

    io.on('connection', (socket) => {
        console.log(`[WS] Client connecte uid=${socket.userId} (${io.engine.clientsCount} total)`);
        socket.on('disconnect', () => console.log(`[WS] Client deconnecte (${io.engine.clientsCount} total)`));
    });
    console.log('[WS] Socket.io initialise (auth activee)');
} catch (e) {
    console.log('[WS] Socket.io non installe, pas de real-time (' + e.message + ')');
    io = null;
}

app.use(express.json());
app.use((req, res, next) => {
    if (req.path === '/api/broadcast') return next();
    if (req.path.startsWith('/dispatch-ws/')) return next();
    const key = req.headers['x-api-key'];
    if (key !== API_KEY) {
        return res.status(401).json({ error: 'Unauthorized' });
    }
    next();
});

app.post('/api/broadcast', (req, res) => {
    const key = req.headers['x-api-key'];
    if (key !== API_KEY) return res.status(401).json({ error: 'Unauthorized' });
    if (io) {
        io.emit('dispatch_update', { type: req.body.type || 'board_changed', ts: Date.now() });
        res.json({ ok: true, clients: io.engine.clientsCount });
    } else {
        res.json({ ok: false, reason: 'socket.io not available' });
    }
});

app.get('/api/roles', async (req, res) => {
    try {
        if (!guild) return res.status(503).json({ error: 'Guild not ready' });

        const roles = guild.roles.cache
            .filter(r => r.id !== GUILD_ID)
            .sort((a, b) => b.position - a.position)
            .map(r => ({
                id: r.id,
                name: r.name,
                color: '#' + r.color.toString(16).padStart(6, '0'),
                position: r.position,
                members: r.members.size
            }));

        syncRolesCache().catch(() => {});

        res.json(roles);
    } catch (err) {
        res.status(500).json({ error: err.message });
    }
});

app.get('/api/member/:discordId', async (req, res) => {
    try {
        if (!guild) return res.status(503).json({ error: 'Guild not ready' });

        let member = guild.members.cache.get(req.params.discordId);
        if (!member) {
            try {
                member = await guild.members.fetch(req.params.discordId);
            } catch (e) {
                return res.status(404).json({ error: 'Member not found' });
            }
        }

        const roleIds = [...member.roles.cache.keys()].filter(r => r !== GUILD_ID);

        res.json({
            id: member.id,
            username: member.user.username,
            nick: member.nickname,
            avatar: member.user.displayAvatarURL({ size: 128 }),
            roles: roleIds
        });
    } catch (err) {
        res.status(500).json({ error: err.message });
    }
});

app.get('/api/members/search', async (req, res) => {
    try {
        if (!guild) return res.status(503).json({ error: 'Guild not ready' });

        const q = (req.query.q || '').toLowerCase().trim();
        if (!q || q.length < 2) return res.json([]);

        const results = [];
        guild.members.cache.forEach(member => {
            if (results.length >= 20) return;
            const nick = member.nickname || '';
            const username = member.user.username || '';
            const id = member.id;

            if (nick.toLowerCase().includes(q) || username.toLowerCase().includes(q) || id.includes(q)) {
                results.push({
                    id: member.id,
                    username: member.user.username,
                    nick: member.nickname,
                    avatar: member.user.displayAvatarURL({ size: 64 })
                });
            }
        });

        res.json(results);
    } catch (err) {
        res.status(500).json({ error: err.message });
    }
});

app.get('/api/guild-info', async (req, res) => {
    try {
        if (!guild) return res.status(503).json({ error: 'Guild not ready' });
        res.json({
            name: guild.name,
            memberCount: guild.memberCount,
            icon: guild.iconURL({ size: 128 })
        });
    } catch (err) {
        res.status(500).json({ error: err.message });
    }
});

app.post('/api/reset-password', async (req, res) => {
    try {
        const { discord_id } = req.body;
        if (!discord_id) return res.status(400).json({ error: 'discord_id requis' });

        const fetch = (await import('node-fetch')).default;
        const phpRes = await fetch('https://exemple.tld/login/auth_api.php?action=reset_password', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-API-Key': API_KEY },
            body: JSON.stringify({ discord_id })
        });
        const phpData = await phpRes.json();

        if (!phpData.success) {
            return res.json({ success: false, error: phpData.error || 'Erreur reset' });
        }

        try {
            const user = await client.users.fetch(discord_id);
            await user.send(
                `🔐 **RP MDT — Reset de mot de passe**\n\n` +
                `Ton mot de passe a ete reinitialise par un administrateur.\n\n` +
                `📋 **Identifiant :** \`${phpData.username}\`\n` +
                `🔑 **Nouveau mot de passe :** \`${phpData.new_password}\`\n\n` +
                `🔗 Connecte-toi sur https://exemple.tld/login\n` +
                `⚠️ Change ton mot de passe apres ta premiere connexion.`
            );
            res.json({ success: true, dm_sent: true, username: phpData.username });
        } catch (dmErr) {
            console.warn('[reset_password] DM bloque pour', discord_id, '— mdp NON renvoye');
            res.json({
                success: true,
                dm_sent: false,
                username: phpData.username,
                dm_error: 'DM bloque par l utilisateur. Demande lui d activer les MP du serveur puis refais un /resetmdp.'
            });
        }
    } catch (err) {
        res.status(500).json({ error: err.message });
    }
});

httpServer.listen(PORT, '127.0.0.1', () => {
    console.log(`[API] Ecoute sur 127.0.0.1:${PORT}` + (io ? ' (Socket.io actif)' : ''));
});

async function loginWithRetry() {
    let delay = 5000;
    const maxDelay = 60000;
    while (true) {
        try {
            await client.login(BOT_TOKEN);
            return;
        } catch (err) {
            console.error('[BOT] Erreur login:', err.message, '— retry dans', delay, 'ms');
            await new Promise(r => setTimeout(r, delay));
            delay = Math.min(delay * 1.5, maxDelay);
        }
    }
}

client.on('ready', async () => {
    if (guild) {
        try {
            console.log('[BOT] Re-sync apres reconnect...');
            await guild.members.fetch();
            await syncRolesCache();
            await syncAllUserRoles();
            console.log('[BOT] Re-sync terminee');
        } catch (err) {
            console.error('[BOT] Erreur re-sync:', err.message);
        }
    }
});

loginWithRetry();
