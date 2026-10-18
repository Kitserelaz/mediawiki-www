<!DOCTYPE html>
<html>
	<head>
		<title>Wiki Not Found</title>
		<style>
			html {
				height: 100%;
			}
			body {
				display: flex;
				align-items: center;
				justify-content: center;
				font-family: sans-serif;
				height: 100%;
			}
			.content {
				display: flex;
				gap: 1rem;
			}
			img {
				height: 15vh;
			}
			.main {
				height: 15vh;
				display: flex;
				flex-direction: column;
				justify-content: center;
				gap: 0.2rem;
			}
			h1 {
				font-size: 1.2rem;
			}	
			h1, p {
				margin: 0;
			}
		</style>
	</head>
	<body>
		<div class="content">
			<img src="https://meta.skywiki.org/images/metawiki/0/05/SkyWiki_logo.png">
			<div class="main">
				<h1>Wiki Not Found</h1>
				<i><?php echo substr($wgDBname, 0, -4) ?></i>
				<p>Check your spelling and try again. You can see a list of our wikis at <a href="https://meta.skywiki.org/wiki/Special:WikiDiscover">Special:WikiDiscover</a> or you can <a href="https://meta.skywiki.org/wiki/Special:RequestWiki">request a wiki</a>.</p>
			</div>
		</div>
	</body>
</html>
