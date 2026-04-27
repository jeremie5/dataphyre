/*!
 * CASPOW (Cryptographic Anti-Spam Proof Of Work)
 * Copyright 2024 Jeremie Frereault
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

$(document).ready(function(){
	$('form').each(function(){
		const form=this;
		let isSubmitting=false;
		$(form).on('submit', async function(event){
			const caspowElement=$(form).find('caspow').first();
			if(caspowElement.length===0 || isSubmitting){
				return true;
			}
			event.preventDefault();
			const endpoint=caspowElement.attr('endpoint');
			const stringOngoing=caspowElement.attr('string_ongoing') || 'Performing cryptographic challenge...';
			const stringFailed=caspowElement.attr('string_failed') || 'Cryptographic challenge failed. Click to try again.';
			const submitButtonSelector=caspowElement.attr('submit');
			const successCallback=caspowElement.attr('success_callback');
			const scope=caspowElement.attr('scope') || deriveScope(form, submitButtonSelector);
			const submitButton=submitButtonSelector ? $(submitButtonSelector).first() : $(form).find('[type="submit"]').first();
			const originalButtonHtml=submitButton.length ? submitButton.html() : null;
			if(submitButton.length){
				submitButton.prop('disabled', true).html(stringOngoing);
			}
			removeHidden(form, 'caspow_result');
			removeHidden(form, '__caspow_submit_name');
			removeHidden(form, '__caspow_submit_value');
			let worker;
			let workerScriptUrl;
			try{
				const challenge=await fetchChallenge(endpoint, scope);
				({ worker, workerScriptUrl }=createWorker());
				let solveResult=await solveChallenge(worker, challenge);
				let proof=extractProof(solveResult);
				if(!proof && shouldFallbackInline(solveResult)){
					solveResult=await solveChallengeInline(challenge, fallbackDurationMs(challenge, solveResult));
					proof=extractProof(solveResult);
				}
				if(!proof){
					throw buildSolveError(solveResult);
				}
				appendHidden(form, 'caspow_result', btoa(JSON.stringify(proof)));
				if(submitButton.length && submitButton.attr('name')){
					appendHidden(form, submitButton.attr('name'), submitButton.val() || submitButton.text() || '1');
				}
				isSubmitting=true;
				if(successCallback && typeof window[successCallback]==='function'){
					window[successCallback].call(form, proof);
				}
				else
				{
					form.submit();
				}
			}catch(error){
				console.error('CASPOW failed', error);
				if(submitButton.length){
					submitButton.prop('disabled', false).html(stringFailed);
					if(originalButtonHtml!==null){
						window.setTimeout(function(){
							if(isSubmitting!==true){
								submitButton.html(originalButtonHtml);
							}
						}, 2500);
					}
				}
			}finally{
				if(worker){
					worker.terminate();
				}
				if(workerScriptUrl){
					URL.revokeObjectURL(workerScriptUrl);
				}
			}
			return false;
		});
	});

	function deriveScope(form, submitButtonSelector){
		const formId=form.getAttribute('id') || '';
		const buttonScope=submitButtonSelector || '';
		const action=form.getAttribute('action') || window.location.pathname;
		return [window.location.pathname, action, formId, buttonScope].filter(Boolean).join('|');
	}

	function appendHidden(form, name, value){
		$('<input>').attr({
			type:'hidden',
			name:name,
			value:value
		}).appendTo(form);
	}

	function removeHidden(form, name){
		$(form).find('input[type="hidden"][name="'+name+'"]').remove();
	}

	function hexString(buffer){
		return Array.from(new Uint8Array(buffer)).map(function(byte){
			return byte.toString(16).padStart(2, '0');
		}).join('');
	}

	function leadingZeroBits(hex){
		let bits=0;
		for(let i=0; i<hex.length; i++){
			const nibble=parseInt(hex[i], 16);
			if(nibble===0){
				bits+=4;
				continue;
			}
			if((nibble & 8)===0){ bits++; } else { return bits; }
			if((nibble & 4)===0){ bits++; } else { return bits; }
			if((nibble & 2)===0){ bits++; } else { return bits; }
			if((nibble & 1)===0){ bits++; }
			return bits;
		}
		return bits;
	}

	function buildProof(challenge, counter, digest, startedAt, worker){
		return {
			version: challenge.version,
			challenge_id: challenge.challenge_id,
			scope: challenge.scope,
			algorithm: challenge.algorithm,
			nonce: challenge.nonce,
			signature: challenge.signature,
			counter: counter,
			digest: digest,
			duration_ms: Math.round(performance.now() - startedAt),
			iterations: counter + 1,
			worker: worker===true
		};
	}

	async function digestHex(algorithm, text){
		if(!window.crypto || !window.crypto.subtle){
			throw new Error('subtle_unavailable');
		}
		const hashBuffer=await window.crypto.subtle.digest(algorithm, new TextEncoder().encode(text));
		return hexString(hashBuffer);
	}

	function normalizeSolveResult(result){
		if(result && typeof result==='object'){
			return result;
		}
		return {
			ok:false,
			reason:'invalid_result'
		};
	}

	function extractProof(result){
		return result && result.ok===true && result.proof ? result.proof : null;
	}

	function shouldFallbackInline(result){
		const reason=String((result && result.reason) || '');
		return reason==='timeout'
			|| reason==='worker_error'
			|| reason==='empty_result'
			|| reason==='invalid_result';
	}

	function fallbackDurationMs(challenge, result){
		const baseDuration=Math.max(250, Number(challenge.max_duration_ms || 1500));
		const reason=String((result && result.reason) || '');
		if(reason==='timeout'){
			return Math.min(Math.max(baseDuration * 2, baseDuration + 1500), 6000);
		}
		return Math.min(baseDuration + 1200, 4000);
	}

	function buildSolveError(result){
		if(result instanceof Error){
			return result;
		}
		const reason=String((result && result.reason) || 'solve_failed');
		const message=(result && result.message) ? reason+': '+String(result.message) : reason;
		const error=new Error(message);
		error.code=reason;
		error.details=result || null;
		return error;
	}

	async function solveChallengeInline(challenge, maxDurationMs){
		const startedAt=performance.now();
		const chunkSize=Math.max(32, Number(challenge.chunk_size || 256));
		const allowedDurationMs=Math.max(250, Number(maxDurationMs || challenge.max_duration_ms || 1500));
		const maxIterations=Math.max(chunkSize, Number(challenge.max_iterations || 1048576));
		const deadline=startedAt + allowedDurationMs;
		const algorithm=challenge.algorithm || 'SHA-256';
		let counter=0;
		try{
			while(counter<maxIterations && performance.now()<deadline){
				const chunkEnd=Math.min(counter + chunkSize, maxIterations);
				for(; counter<chunkEnd; counter++){
					const digest=await digestHex(algorithm, challenge.challenge_id + ':' + challenge.nonce + ':' + counter);
					if(leadingZeroBits(digest) >= challenge.difficulty_bits){
						return {
							ok:true,
							proof:buildProof(challenge, counter, digest, startedAt, false)
						};
					}
				}
				await new Promise(function(resolve){
					window.setTimeout(resolve, 0);
				});
			}
			return {
				ok:false,
				reason:'timeout',
				duration_ms:Math.round(performance.now() - startedAt),
				iterations:counter
			};
		}catch(error){
			return {
				ok:false,
				reason:'inline_error',
				message:error && error.message ? String(error.message) : 'inline_error'
			};
		}
	}

	function createWorker(){
		const workerScript=`(() => {
			"use strict";
			const encoder=new TextEncoder();
			function hexString(buffer){
				return Array.from(new Uint8Array(buffer)).map(byte => byte.toString(16).padStart(2, "0")).join("");
			}
			function leadingZeroBits(hex){
				let bits=0;
				for(let i=0; i<hex.length; i++){
					const nibble=parseInt(hex[i], 16);
					if(nibble===0){
						bits+=4;
						continue;
					}
					if((nibble & 8)===0){ bits++; } else { return bits; }
					if((nibble & 4)===0){ bits++; } else { return bits; }
					if((nibble & 2)===0){ bits++; } else { return bits; }
					if((nibble & 1)===0){ bits++; }
					return bits;
				}
				return bits;
			}
			function buildProof(challenge, counter, digest, startedAt){
				return {
					version: challenge.version,
					challenge_id: challenge.challenge_id,
					scope: challenge.scope,
					algorithm: challenge.algorithm,
					nonce: challenge.nonce,
					signature: challenge.signature,
					counter: counter,
					digest: digest,
					duration_ms: Math.round(performance.now() - startedAt),
					iterations: counter + 1,
					worker: true
				};
			}
			function failure(reason, extra){
				return Object.assign({
					ok: false,
					reason: reason
				}, extra || {});
			}
			async function digestHex(algorithm, text){
				if(!self.crypto || !self.crypto.subtle){
					throw new Error("subtle_unavailable");
				}
				const hashBuffer=await self.crypto.subtle.digest(algorithm, encoder.encode(text));
				return hexString(hashBuffer);
			}
			async function solve(challenge){
				const startedAt=performance.now();
				const chunkSize=Math.max(32, Number(challenge.chunk_size || 256));
				const maxDurationMs=Math.max(250, Number(challenge.max_duration_ms || 1500));
				const maxIterations=Math.max(chunkSize, Number(challenge.max_iterations || 1048576));
				const deadline=startedAt + maxDurationMs;
				const algorithm=challenge.algorithm || "SHA-256";
				let counter=0;
				while(counter<maxIterations && performance.now()<deadline){
					const chunkEnd=Math.min(counter + chunkSize, maxIterations);
					for(; counter<chunkEnd; counter++){
						const digest=await digestHex(algorithm, challenge.challenge_id + ":" + challenge.nonce + ":" + counter);
						if(leadingZeroBits(digest) >= challenge.difficulty_bits){
							return {
								ok: true,
								proof: buildProof(challenge, counter, digest, startedAt)
							};
						}
					}
					await new Promise(resolve => setTimeout(resolve, 0));
				}
				return failure("timeout", {
					duration_ms: Math.round(performance.now() - startedAt),
					iterations: counter
				});
			}
			self.onmessage=async function(event){
				try{
					const result=await solve(event.data);
					self.postMessage(result);
				}catch(error){
					self.postMessage(failure("worker_error", {
						message: error && error.message ? String(error.message) : "worker_error"
					}));
				}
			};
		})();`;
		const blob=new Blob([workerScript], { type:'application/javascript' });
		const workerScriptUrl=URL.createObjectURL(blob);
		return {
			worker:new Worker(workerScriptUrl),
			workerScriptUrl:workerScriptUrl
		};
	}

	function solveChallenge(worker, challenge){
		return new Promise(function(resolve, reject){
			let settled=false;
			const failTimer=window.setTimeout(function(){
				if(settled){
					return;
				}
				settled=true;
				resolve({
					ok:false,
					reason:'timeout'
				});
			}, Math.max(1000, Number(challenge.max_duration_ms || 1500) + 1500));
			worker.onmessage=function(event){
				if(settled){
					return;
				}
				settled=true;
				window.clearTimeout(failTimer);
				resolve(normalizeSolveResult(event.data));
			};
			worker.onerror=function(error){
				if(settled){
					return;
				}
				settled=true;
				window.clearTimeout(failTimer);
				resolve({
					ok:false,
					reason:'worker_error',
					message:error && error.message ? String(error.message) : 'worker_error'
				});
			};
			worker.postMessage(challenge);
		});
	}

	async function fetchChallenge(endpoint, scope){
		const response=await fetch(endpoint, {
			method:'POST',
			headers:{
				'Content-Type':'application/json',
				'Accept':'application/json'
			},
			body:JSON.stringify({
				scope:scope,
				capabilities:collectCapabilities()
			})
		});
		if(!response.ok){
			throw new Error('challenge_fetch_failed');
		}
		return response.json();
	}

	function collectCapabilities(){
		const connection=navigator.connection || navigator.mozConnection || navigator.webkitConnection || null;
		return {
			hardware_concurrency:Number(navigator.hardwareConcurrency || 0),
			device_memory:Number(navigator.deviceMemory || 0),
			save_data:!!(connection && connection.saveData),
			reduced_motion:typeof window.matchMedia==='function' && window.matchMedia('(prefers-reduced-motion: reduce)').matches
		};
	}
});
