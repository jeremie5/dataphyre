<?php
/*************************************************************************
*  2020-2022 Shopiro Ltd.
*  All Rights Reserved.
* 
* NOTICE:  All information contained herein is, and remains the 
* property of Shopiro Ltd. and its suppliers, if any. The 
* intellectual and technical concepts contained herein are 
* proprietary to Shopiro Ltd. and its suppliers and may be 
* covered by Canadian and Foreign Patents, patents in process, and 
* are protected by trade secret or copyright law. Dissemination of 
* this information or reproduction of this material is strictly 
* forbidden unless prior written permission is obtained from Shopiro Ltd..
*/

$stopwords=array(
'a', 'acerca', 'adeus', 'agora', 'ainda', 'alem', 'algmas', 'algo', 'algumas', 'alguns', 'ali', 'além', 'ambas',
'ambos', 'ano', 'anos', 'antes', 'ao', 'aos', 'apenas', 'apoio', 'apontar', 'apos', 'aquela', 'aquelas', 'aquele',
'aqueles', 'aqui', 'aquilo', 'as', 'assim', 'através', 'atrás', 'até', 'aí', 'baixo', 'bastante', 'bem', 'boa',
'boas', 'bom', 'bons', 'breve', 'cada', 'caminho', 'catorze', 'cedo', 'cento', 'certamente', 'certeza', 'cima',
'cinco', 'coisa', 'com', 'como', 'comprido', 'conhecido', 'conselho', 'contra', 'corrente', 'custa', 'cá', 'da',
'daquela', 'daquelas', 'daquele', 'daqueles', 'dar', 'das', 'de', 'debaixo', 'demais', 'dentro', 'depois',
'desde', 'desligado', 'dessa', 'dessas', 'desse', 'desses', 'desta', 'destas', 'deste', 'destes', 'deve', 'devem',
'deverá', 'dez', 'dezanove', 'dezasseis', 'dezassete', 'dezoito', 'dia', 'diante', 'direita', 'dispoe', 'dispoem',
'diversa', 'diversas', 'diversos', 'diz', 'dizem', 'dizer', 'do', 'dois', 'dos', 'doze', 'duas', 'dá', 'dão',
'dúvida', 'e', 'ela', 'elas', 'ele', 'eles', 'em', 'embora', 'enquanto', 'entre', 'então', 'era', 'essa', 'essas',
'esse', 'esses', 'esta', 'estado', 'estar', 'estará', 'estas', 'estava', 'este', 'estes', 'esteve', 'estive',
'estivemos', 'estiveram', 'estiveste', 'estivestes', 'estou', 'etc', 'eu', 'exemplo', 'falta', 'fará', 'favor',
'faz', 'fazeis', 'fazem', 'fazemos', 'fazendo', 'fazer', 'fazes', 'feito', 'fez', 'fim', 'final', 'foi', 'fomos',
'for', 'fora', 'foram', 'forma', 'foste', 'fostes', 'fui', 'geral', 'grande', 'grandes', 'grupo', 'ha', 'haja',
'hajam', 'havemos', 'havia', 'hei', 'hoje', 'hora', 'horas', 'houve', 'houvemos', 'houver','houvera', 
'houverá', 'houveram', 'houverão', 'houveria', 'houveriam', 'houvermos', 'houverá', 'houvesse',
'houvessem', 'há', 'hão', 'idem', 'igual', 'imediatamente', 'imensos', 'inicio', 'inserir', 'inteiro', 'isso',
'isto', 'já', 'la', 'lado', 'ligado', 'local', 'logo', 'longe', 'lugar', 'lá', 'maior', 'maioria', 'maiorias',
'mais', 'mal', 'mas', 'me', 'meio', 'menor', 'menos', 'meses', 'mesma', 'mesmas', 'mesmo', 'mesmos', 'meu', 'meus',
'mil', 'minha', 'minhas', 'momento', 'muito', 'muitos', 'máximo', 'mês', 'na', 'nada', 'nao', 'naquela', 'naquelas',
'naquele', 'naqueles', 'nas', 'nem', 'nenhuma', 'nessa', 'nessas', 'nesse', 'nesses', 'nesta', 'nestas', 'neste',
'nestes', 'ninguem', 'ninguém', 'nisso', 'no', 'nos', 'nossa', 'nossas', 'nosso', 'nossos', 'nova', 'novas', 'nove',
'novo', 'novos', 'num', 'numa', 'nunca', 'nós', 'o', 'obra', 'obrigada', 'obrigado', 'oitava', 'oitavo', 'oito',
'onde', 'ontem', 'onze', 'ora', 'os', 'ou', 'outra', 'outras', 'outros', 'para', 'parece', 'parte', 'partir', 'pegar',
'pela', 'pelas', 'pelo', 'pelos', 'perto', 'pode', 'podem', 'poder', 'poderá', 'podia', 'ponto', 'pontos', 'por',
'porquanto', 'porque', 'porquê', 'portanto', 'posicao', 'posição', 'possivelmente', 'posso', 'possível', 'pouca',
'pouco', 'povo', 'primeira', 'primeiro', 'próprio', 'próximo', 'puderam', 'pôde', 'põe', 'põem', 'quais', 'qual',
'qualquer', 'quando', 'quanto', 'quarta', 'quarto', 'quatro', 'que', 'quem', 'quer', 'querem', 'quero', 'questao',
'quinta', 'quinto', 'quinze', 'quê', 'relacao', 'relação', 'respeito', 'sabe', 'saber', 'se', 'segunda', 'segundo',
'sei', 'seis', 'sem', 'sempre', 'ser', 'sera', 'será', 'sete', 'seu', 'seus', 'sexta', 'sexto', 'sim', 'sistema', 'sob', 
'sobre', 'sois', 'somente', 'somos', 'sou', 'sua', 'suas', 'são', 'sétima', 'sétimo', 'só', 'tais', 'tal', 'talvez',
'tambem', 'também', 'tanta', 'tantas', 'tanto', 'tarde', 'te', 'tem', 'temos', 'tempo', 'tendes', 'tenho', 'tens',
'tentar', 'tentaram', 'tente', 'tentei', 'ter', 'terceira', 'terceiro', 'teu', 'teus', 'teve', 'tipo', 'tive',
'tivemos', 'tiver', 'tivera', 'tiveram', 'tiverem', 'tivermos', 'tivesse', 'tivessem', 'tiveste', 'tivestes', 'toda',
'todas', 'todo', 'todos', 'treze', 'três', 'tu', 'tua', 'tuas', 'tudo', 'tão', 'têm', 'um', 'uma', 'umas', 'uns',
'usa', 'usar', 'vai', 'vais', 'valor', 'veja', 'vem', 'vens', 'ver', 'vez', 'vezes', 'viagem', 'vindo', 'vinte',
'você', 'vocês', 'vos', 'vossa', 'vossas', 'vosso', 'vossos', 'vários', 'vão', 'vêm', 'vós', 'zero', 'à', 'às',
'área', 'é', 'és', 'último');