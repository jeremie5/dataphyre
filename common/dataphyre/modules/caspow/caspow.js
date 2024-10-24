/*!
 * CASPOW (Cryptographic Anti-Spam Proof Of Work)
 * Copyright 2024 Jérémie Fréreault
 *
 * Licensed under the Apache License,Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing,software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND,either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

$(document).ready(function(){
    $('form').each(function(){
        let isSubmitting=false;
        $(this).on('submit',function(e){
            const caspowElement=$(this).find('caspow');
            if(caspowElement.length===0){
                return true;
            }
            if(!isSubmitting){
                e.preventDefault();
                const caspowElement=$(this).find('caspow');
                const endpoint=caspowElement.attr('endpoint');
                const stringOngoing=caspowElement.attr('string_ongoing');
                const stringFailed=caspowElement.attr('string_failed');
                const submitButtonSelector=caspowElement.attr('submit');
                const successCallback=caspowElement.attr('success_callback');
                const submitButton=$(submitButtonSelector);
                submitButton.html(stringOngoing);
                const workerScript=`(function(){
                    "use strict";
                    const encoder=new TextEncoder();
                    function hexString(buffer){
                        return[...new Uint8Array(buffer)].map(b=>b.toString(16).padStart(2,"0")).join("");
                    }
                    async function hash(salt,number,algorithm){
                        return hexString(await crypto.subtle.digest(algorithm.toUpperCase(),encoder.encode(salt+number)));
                    }
                    async function findMatch(challenge,salt,algorithm="SHA-256",maxIterations=1e7){
                        const startTime=Date.now();
                        for(let i=0; i<=maxIterations; i++){
                            if(await hash(salt,i,algorithm)===challenge){
                                return{
                                    number:i,
                                    took:Date.now()-startTime
                                };
                            }
                        }
                        return null;
                    }
                    onmessage=async e=>{
                        const{ algorithm,challenge,maxIterations,salt}=e.data||{};
                        if(challenge && salt){
                            const result=await findMatch(challenge,salt,algorithm,maxIterations);
                            postMessage(result?{...result,worker:true}:null);
                        }else{
                            postMessage(null);
                        }
                    };
                })();`;
                const blob=new Blob([workerScript],{
                    type:'application/javascript'
                });
                const workerScriptURL=URL.createObjectURL(blob);
                const worker=new Worker(workerScriptURL);
                fetchChallenge(endpoint).then(challenge => {
                    worker.postMessage(challenge);
                    const fetchedChallenge=challenge;
                    worker.onmessage=function(e){
                        if(e.data){
                            const resultPayload={
                                number:e.data.number,
                                took:e.data.took,
                                worker:e.data.worker,
                                salt:fetchedChallenge.salt,
                                algorithm:fetchedChallenge.algorithm,
                                challenge:fetchedChallenge.challenge,
                                signature:fetchedChallenge.signature
                            };
                            const base64Payload=btoa(JSON.stringify(resultPayload));
                            $('<input>').attr({
                                type:'hidden',
                                name:'caspow_result',
                                value:base64Payload
                            }).appendTo(this);
                            isSubmitting=true;
                            $(this).unbind('submit');
                            if(successCallback != null){
                                console.log("Calling success callback to " + successCallback);
                                window[successCallback].call(this,e);
                            }else{
                                console.log("Simulating click on submit button");
                                $(submitButton).click();
                            }
                        }else{
                            submitButton.html(stringFailed);
                        }
                    }.bind(this);
                });
            }
        });
    });
    async function fetchChallenge(endpoint){
        const response=await fetch(endpoint,{
            method:'GET'
        });
        if(!response.ok) throw new Error('Failed to fetch the challenge parameters');
        return response.json();
    }
});