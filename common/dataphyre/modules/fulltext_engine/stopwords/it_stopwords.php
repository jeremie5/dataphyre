<?php
 /*************************************************************************
 *  2020-2024 Shopiro Ltd.
 *  All Rights Reserved.
 * 
 * NOTICE: All information contained herein is, and remains the 
 * property of Shopiro Ltd. and is provided under a dual licensing model.
 * 
 * This software is available for personal use under the Free Personal Use License.
 * For commercial applications that generate revenue, a Commercial License must be 
 * obtained. See the LICENSE file for details.
 *
 * This software is provided "as is", without any warranty of any kind.
 */


$stopwords=array(
'a', 'abbia', 'abbiamo', 'abbiano', 'abbiate', 'ad', 'agl', 'agli', 'ai', 'al', 'all', 'alla', 'alle',
'allo', 'anche', 'avemmo', 'avendo', 'avesse', 'avessero', 'avessi', 'avessimo', 'aveste', 'avesti',
'avete', 'aveva', 'avevamo', 'avevano', 'avevate', 'avevi', 'avevo', 'avrai', 'avranno', 'avrebbe',
'avrebbero', 'avrei', 'avremmo', 'avremo', 'avreste', 'avresti', 'avrete', 'avrà', 'avrò', 'avuta',
'avute', 'avuti', 'avuto', 'c', 'che', 'chi', 'ci', 'coi', 'col', 'come', 'con', 'contro', 'cui', 'da',
'dagl', 'dagli', 'dai', 'dal', 'dall', 'dalla', 'dalle', 'dallo', 'degl', 'degli', 'dei', 'del', 'dell',
'della', 'delle', 'dello', 'di', 'dov', 'dove', 'e', 'ebbe', 'ebbero', 'ebbi', 'è', 'è', 'ed', 'era',
'erano', 'eravamo', 'eravate', 'eri', 'ero', 'essendo', 'faccia', 'facciamo', 'facciano', 'facciate',
'faccio', 'facemmo', 'facendo', 'facesse', 'facessero', 'facessi', 'facessimo', 'faceste', 'facesti',
'faceva', 'facevamo', 'facevano', 'facevate', 'facevi', 'facevo', 'fai', 'fanno', 'farai', 'faranno',
'farebbe', 'farebbero', 'farei', 'faremmo', 'faremo', 'fareste', 'faresti', 'farete', 'farà', 'farò',
'fece', 'fecero', 'feci', 'fosse', 'fossero', 'fossi', 'fossimo', 'foste', 'fosti', 'fra', 'fu',
'fui', 'fummo', 'furono', 'gli', 'ha', 'hai', 'hanno', 'ho', 'i', 'il', 'in', 'io', 'l', 'la', 'le',
'lei', 'li', 'lo', 'loro', 'lui', 'ma', 'me', 'mi', 'mia', 'mie', 'mio', 'ne', 'negl', 'negli', 'nei',
'nel', 'nell', 'nella', 'nelle', 'nello', 'noi', 'non', 'nostra', 'nostre', 'nostri', 'nostro', 'o',
'per', 'perché', 'più', 'pochi', 'poco', 'qual', 'quale', 'quali', 'quando', 'quanto', 'quanti',
'quanto', 'quasi', 'quattro', 'quel', 'quella', 'quelle', 'quelli', 'quello', 'quest', 'questa',
'queste', 'questi', 'questo', 'qui', 'quindi', 'sarai', 'saranno', 'sarebbe', 'sarebbero', 'sarei',
'saremmo', 'saremo', 'sareste', 'saresti', 'sarete', 'sarà', 'sarò', 'se', 'sei', 'senza', 'si',
'sia', 'siamo', 'siano', 'siate', 'siete', 'sono', 'sta', 'stai', 'stando', 'stanno', 'starai',
'staranno', 'starebbe', 'starebbero', 'starei', 'staremmo', 'staremo', 'stareste', 'staresti',
'starete', 'starà', 'starò', 'stava', 'stavamo', 'stavano', 'stavate', 'stavi', 'stavo', 'stemmo',
'stesse', 'stessero', 'stessi', 'stessimo', 'stesso', 'steste', 'stesti', 'sti', 'su', 'sua', 'subito',
'sue', 'sugl', 'sugli', 'sui', 'sul', 'sull', 'sulla', 'sulle', 'sullo', 'suo', 'suoi', 'ti', 'tra',
'tu', 'tua', 'tue', 'tuo', 'tuoi', 'tutti', 'tutto', 'un', 'una', 'uno', 'vi', 'voi', 'vostra',
'vostre', 'vostri', 'vostro');