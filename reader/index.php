<!DOCTYPE html>
<html>
	<head>
		<title>Image Encryption</title>
		<meta charset="utf-8"></meta>
		<style type="text/css">
			body {
				margin: 0px;
				background-color: #333;
				color: #eee;
				font-family: ubuntu;

			}
			button, select {
				padding: 5px;
				padding-left: 15px;
				padding-right: 15px;
				
				background-color: orange;
				
				border: none;
				border: 2px solid rgba(0,0,0,0.3);
				border-radius: 10px;
				
				font-size: 1.2em;
				
				cursor: pointer;
			}
			button:hover, select:hover {
				background-color: #c77;
				border: 2px solid rgba(0,0,0,1);
			}
			.manga-list {
				margin: 5px;
				padding: 5px;
				
				background-color: #222;
				border: 2px solid #111;
				border-radius: 5px;
			}
			.manga-list * {
				display: inline-block;
				
				padding: 5px;
				
				background-color: #000;
				border: 2px solid #111;
				border-radius: 5px;
				
				color: #ccc;
				
				text-decoration: none;
			}
			.manga-list *:hover {
				background-color: red;
				border: 2px solid rgba(0,0,0,0.7);
				
				color: #000;
			}
			.chapter-navigation {
				margin: 5px;
				padding: 5px;
				
				background-color: #222;
				border: 2px solid #111;
				border-radius: 5px;
			}
			.images * {
				display: block;
				width: 100%;
			}
		</style>
	</head>
	<body>
		<div class="manga-list" id="manga-list"></div>
		<div class="chapter-navigation">
			Chapter: <select id="chapter-select"></select>
			<button id="prev-button">prev</button>
			<button id="next-button">next</button>
		</div>
		<div class="images" id="images"></div>
		<div class="chapter-navigation">
			Chapter: <select id="chapter-select"></select>
			<button id="prev-button">prev</button>
			<button id="next-button">next</button>
		</div>

		<script type="text/javascript">
			let ip = "<?php echo $_SERVER['REMOTE_ADDR'];?>";
			if(ip.indexOf("php")>=0) ip = "0.0.0.0";
			let ipBits = [];
			let numbers = ip.split(".");
			for(let i=0; i<numbers.length; i++) {
				let number = parseInt(numbers[i]);
				for(let j=0; j<8; j++) {
					ipBits.push(1&(number>>(7-j)));
				}
			}

			const buff_to_base64 = (buff) => btoa(String.fromCharCode.apply(null, buff));

			const base64_to_buf = (b64) =>
				Uint8Array.from(atob(b64), (c) => c.charCodeAt(null));

			const enc = new TextEncoder();
			const dec = new TextDecoder();

			const getPasswordKey = (password) =>
				window.crypto.subtle.importKey("raw", enc.encode(password), "PBKDF2", false, [
					"deriveKey",
				]);

			const deriveKey = (passwordKey, salt, keyUsage) => {
				let userKey = window.crypto.subtle.deriveKey(
					{
						name: "PBKDF2",
						salt: salt,
						iterations: 1000,
						hash: "SHA-256",
					},
					passwordKey,
					{ name: "AES-GCM", length: 256 },
					false,
					keyUsage
				);
				return userKey;
			};
				

			async function encryptData(secretData, password) {
				const salt = window.crypto.getRandomValues(new Uint8Array(16));
				const iv = window.crypto.getRandomValues(new Uint8Array(12));
				const passwordKey = await getPasswordKey(password);
				const aesKey = await deriveKey(passwordKey, salt, ["encrypt"]);
				const encryptedContent = await window.crypto.subtle.encrypt(
					{
						name: "AES-GCM",
						iv: iv,
					},
					aesKey,
					secretData
				);

				const encryptedContentArr = new Uint8Array(encryptedContent);
				let buff = new Uint8Array(
					salt.byteLength + iv.byteLength + encryptedContentArr.byteLength
				);
				buff.set(salt, 0);
				buff.set(iv, salt.byteLength);
				buff.set(encryptedContentArr, salt.byteLength + iv.byteLength);
				return buff;
			}

			async function decryptData(encryptedData, password) {
				const salt = encryptedData.slice(0, 16);
				const iv = encryptedData.slice(16, 16 + 12);
				const data = encryptedData.slice(16 + 12);
				const passwordKey = await getPasswordKey(password);
				const aesKey = await deriveKey(passwordKey, salt, ["decrypt"]);
				const decryptedContent = await window.crypto.subtle.decrypt(
					{
						name: "AES-GCM",
						iv: iv,
					},
					aesKey,
					data
				);
				return decryptedContent;
			}



			const sha256 = async(string)=>{
				let stringBuffer = enc.encode(string);
				let hashBuffer = await crypto.subtle.digest({name:"SHA-256"}, stringBuffer);
				let hashBase16 = [...new Uint8Array(hashBuffer)].map(x => x.toString(16).padStart(2, '0')).join('');
				let hashBase64 = btoa(String.fromCharCode(...new Uint8Array(hashBuffer)));
				return hashBase16;
			};



			const getChapterKey = async(imageServer,manga,chapter,userKey)=>{
				let user = await sha256(userKey);
				let arrayBuffer = await (await fetch(`${imageServer}/access-control/${user}/${manga}/${chapter}.chapterKey.encrypted`)).arrayBuffer();
				let decryptedBuffer = await decryptData(new Uint8Array(arrayBuffer),userKey);
				let decryptedString = dec.decode(decryptedBuffer);
				return decryptedString;
			};
			const processImage = async(imageUrl, watermark)=>{
				console.time("processImage");
				let image = await new Promise((res)=>{
					let image = new Image();
					image.onload = ()=>{res(image);};
					image.src = imageUrl;
				});
				URL.revokeObjectURL(imageUrl);
				console.time("draw Image & get imageData");
				let canvas = document.createElement("canvas");
				let c = canvas.getContext("2d");
				canvas.width = image.width;
				canvas.height = image.height;
				c.drawImage(image,0,0);
				let imageData = c.getImageData(0,0,image.width,image.height);
				console.timeEnd("draw Image & get imageData");
				let bits = [];
				for(let i=0; i<watermark.length; i++) {
					let hexDigitCode = parseInt(watermark[i],16);
					for(let j=0; j<4; j++) {
						bits.push(1&(hexDigitCode>>(3-j)));
					}
				}
				// set last bits to ip address
				bits = bits.slice(0,256-32).concat(ipBits);
				console.time("imageData manipulation");
				for(let y=0; y<imageData.height; y++) {
					for(let x=0; x<imageData.width; x++) {
						let bitIndex = (x>>4) + (y>>4)*((imageData.width>>4)-1);
						let index = x+y*imageData.width;
						imageData.data[index*4+0] &= ~1;
						imageData.data[index*4+0] |= bits[(bitIndex*3+0)&255];
						imageData.data[index*4+1] &= ~1;
						imageData.data[index*4+1] |= bits[(bitIndex*3+1)&255];
						imageData.data[index*4+2] &= ~1;
						imageData.data[index*4+2] |= bits[(bitIndex*3+2)&255];
					}
				}
				console.timeEnd("imageData manipulation");
				c.putImageData(imageData,0,0);

				console.timeEnd("processImage");
				return canvas;

				// c.fillText(watermark,0,20);
				return await new Promise((res)=>{
					console.time("blobify image");
					canvas.toBlob((blob)=>{
						res(URL.createObjectURL(blob));
						console.timeEnd("blobify image");
						console.timeEnd("processImage");
					});
				});
			};
			const displayUrl = async(imageServer,url,userKey)=>{
				location.hash = `#${imageServer}#${url}#${userKey}`;
				let urlFolders = url.split("/");
				let manga = urlFolders[1];
				let chapter = urlFolders[2];
				let user = await sha256(userKey);

				let siteInfo = await (await fetch(`${imageServer}/index.json`)).json();

				// load manga list
				document.querySelector("#manga-list").innerHTML = "";
				for(let i=0; i<siteInfo.mangaList.length; i++) {
					let mangaName = siteInfo.mangaList[i];
					let mangaInfo = await (await fetch(`${imageServer}/manga/${mangaName}/index.json`)).json();
					let lastChapter = mangaInfo.chapters[mangaInfo.chapters.length-1];

					let a = document.createElement("a");
					a.href = `/${mangaName}/${lastChapter}`;
					a.innerText = mangaName;
					document.querySelector("#manga-list").appendChild(a);
					a.addEventListener("click",(e)=>{
						displayUrl(imageServer,`/${mangaName}/${lastChapter}/`,userKey);
						e.preventDefault();
						return false;
					});
				}

				let mangaInfo = await (await fetch(`${imageServer}/manga/${manga}/index.json`)).json();
				
				// load chapter select
				const loadChapterSelect = (chapterSelect)=>{
					chapterSelect.innerHTML = "";
					for(let i=0; i<mangaInfo.chapters.length; i++) {
						let chapterName = mangaInfo.chapters[i];

						let option = document.createElement("option");
						option.innerText = chapterName;
						chapterSelect.appendChild(option);
						if(chapterName === chapter) option.selected = true;
						option.addEventListener("click",()=>{
							displayUrl(imageServer,`/${manga}/${chapterName}/`,userKey);
						});
					}
				};
				document.querySelectorAll("#chapter-select").forEach(e=>loadChapterSelect(e))

				let chapterIndex = mangaInfo.chapters.indexOf(chapter);
				// prev button
				if(chapterIndex>0) {
					const prev = ()=>{
						let prevChapter = mangaInfo.chapters[chapterIndex-1];
						displayUrl(imageServer,`/${manga}/${prevChapter}/`,userKey)
					};
					document.querySelectorAll("#prev-button").forEach(e=>e.onclick=prev);
				}
				// next button
				if(chapterIndex<mangaInfo.chapters.length-1) {
					const next = ()=>{
						let nextChapter = mangaInfo.chapters[chapterIndex+1];
						displayUrl(imageServer,`/${manga}/${nextChapter}/`,userKey)
					};
					document.querySelectorAll("#next-button").forEach(e=>e.onclick=next);
				}

				let chapterUrl = `${imageServer}/manga/${manga}/${chapter}/`;
				let chapterInfo = await (await fetch(chapterUrl+"index.json")).json();

				// load images
				let chapterKey = await getChapterKey(imageServer,manga,chapter,userKey);
				console.log("chapterKey:",chapterKey);
				document.querySelector("#images").innerHTML = "";
				for(let i=0; i<chapterInfo.imageUrls.length; i++) {
					// (async()=>{
						if(location.hash !== `#${imageServer}#${url}#${userKey}`) return;
						// let img = document.createElement("img");
						// document.querySelector("#images").appendChild(img);

						let encryptedImageUrl = chapterUrl + chapterInfo.imageUrls[i];
						if(location.hash !== `#${imageServer}#${url}#${userKey}`) return;
						let arrayBuffer = await (await fetch(encryptedImageUrl)).arrayBuffer();
						if(location.hash !== `#${imageServer}#${url}#${userKey}`) return;
						let decrypted = await decryptData(new Uint8Array(arrayBuffer),chapterKey);
						if(location.hash !== `#${imageServer}#${url}#${userKey}`) return;
						let blob = new Blob([decrypted]);
						if(location.hash !== `#${imageServer}#${url}#${userKey}`) return;
						let imageUrl = URL.createObjectURL(blob);
						// let watermarkedUrl = await processImage(imageUrl,user);
						// img.src = watermarkedUrl;
						let canvas = await processImage(imageUrl,user);
						document.querySelector("#images").appendChild(canvas);
					// })()
				}
			};

			setInterval(()=>{
				window.parent.postMessage({setIframeHeight:window.innerHeight},"*");
			},250);
			
			if(location.hash.length>1) {
				console.log(location.hash);
				let string = decodeURI(location.hash.slice(1));
				let args = string.split("#");
				displayUrl(args[0],args[1],args[2]);
			}
		</script>
	</body>
</html>