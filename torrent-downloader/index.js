var torrentStream = require('torrent-stream');
var http = require('http');
var fs = require('fs');
var optimist = require('optimist');
var os = require('os');
var readTorrent = require('read-torrent');

var argv = optimist
	.usage('Usage: $0 magnet-link-or-torrent [options]')
	.argv;


var ontorrent = function(torrent,opts) {
	if (!opts) opts = {};
	var engine = torrentStream(torrent, opts);

	// Just want torrent-stream to list files.
	if (opts.list) return engine;

	// Pause/Resume downloading as needed
	engine.on('uninterested', function() { engine.swarm.pause();  });
	engine.on('interested',   function() { engine.swarm.resume(); });

	engine.server = createServer(engine, opts.index);

	// Listen when torrent-stream is ready, by default a random port.
	engine.on('ready', function() { engine.server.listen(opts.port || 0); });

	var swarm = engine.swarm;

	
	engine.server.on('listening',function(){
		var filelength = engine.server.index.length;

		var checkFinished = function(){
			if(swarm.downloaded >= filelength){
				var file = engine.path+'/'+engine.files[0].getInfo().path;
				var stats = fs.statSync(file);
				var downloadedFileSize = stats['size'];
				if(downloadedFileSize == filelength){
					console.log(file);
					process.exit();
				}

			}
		};
		setInterval(checkFinished, 500);
		checkFinished();
	});
}


var createServer = function(e, index) {
	var server = http.createServer();
	var onready = function() {
		if (typeof index !== 'number') {
			index = e.files.reduce(function(a, b) {
				return a.length > b.length ? a : b;
			});
			index = e.files.indexOf(index);
		}

		e.files[index].select();
		server.index = e.files[index];
	};


	if (e.torrent){onready();}
	else e.on('ready', onready);

	return server;
};

var filename = argv._[0];

if (!filename) {
	optimist.showHelp();
	process.exit(1);
}


if (/^magnet:/.test(filename)) return ontorrent(filename,argv);

readTorrent(filename, function(err, torrent) {
	if (err) {
		console.error(err.message);
		process.exit(1);
	}

	ontorrent(torrent,argv);
});




