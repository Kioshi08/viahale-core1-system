const express = require('express');
const http = require('http');
const socketIO = require('socket.io');

const app = express();
const server = http.createServer(app);
const io = socketIO(server, {
    cors: { origin: "*" }
});

io.on('connection', (socket) => {
    console.log('Dispatcher connected:', socket.id);

    socket.on('disconnect', () => {
        console.log('Dispatcher disconnected:', socket.id);
    });
});

// Trigger this in PHP via HTTP call to send updates
app.get('/update', (req, res) => {
    io.emit('updateData', { type: req.query.type || 'general' });
    res.send({ status: 'ok' });
});

server.listen(3001, () => {
    console.log('Socket.IO server running on port 3001');
});
