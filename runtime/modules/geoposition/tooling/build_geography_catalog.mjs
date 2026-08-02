#!/usr/bin/env node

import { readFile, writeFile } from "node:fs/promises";
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";

const moduleDirectory = resolve(dirname(fileURLToPath(import.meta.url)), "..");
const isoDirectory = process.argv[2] ?? "/usr/share/iso-codes";
const outputPath = process.argv[3] ?? resolve(moduleDirectory, "datasets/geography_catalog.json");

function readUInt32(buffer, offset, littleEndian) {
  return littleEndian ? buffer.readUInt32LE(offset) : buffer.readUInt32BE(offset);
}

function parseMo(buffer) {
  if (buffer.length < 28) return new Map();
  const littleEndian = buffer.readUInt32LE(0) === 0x950412de;
  const bigEndian = buffer.readUInt32BE(0) === 0x950412de;
  if (!littleEndian && !bigEndian) return new Map();

  const count = readUInt32(buffer, 8, littleEndian);
  const originalsOffset = readUInt32(buffer, 12, littleEndian);
  const translationsOffset = readUInt32(buffer, 16, littleEndian);
  const messages = new Map();

  for (let index = 0; index < count; index += 1) {
    const originalLength = readUInt32(buffer, originalsOffset + index * 8, littleEndian);
    const originalOffset = readUInt32(buffer, originalsOffset + index * 8 + 4, littleEndian);
    const translatedLength = readUInt32(buffer, translationsOffset + index * 8, littleEndian);
    const translatedOffset = readUInt32(buffer, translationsOffset + index * 8 + 4, littleEndian);
    const original = buffer.subarray(originalOffset, originalOffset + originalLength).toString("utf8").split("\0")[0] ?? "";
    const translated = buffer.subarray(translatedOffset, translatedOffset + translatedLength).toString("utf8").split("\0")[0] ?? "";
    const messageId = (original.includes("\u0004") ? original.slice(original.lastIndexOf("\u0004") + 1) : original).trim();
    if (messageId && translated.trim()) messages.set(messageId, translated.trim());
  }

  return messages;
}

async function readJson(path) {
  return JSON.parse(await readFile(path, "utf8"));
}

async function translations(path) {
  try {
    return parseMo(await readFile(path));
  } catch {
    return new Map();
  }
}

const [countryDocument, subdivisionDocument, frenchCountries, frenchSubdivisions] = await Promise.all([
  readJson(resolve(isoDirectory, "json/iso_3166-1.json")),
  readJson(resolve(isoDirectory, "json/iso_3166-2.json")),
  translations("/usr/share/locale/fr/LC_MESSAGES/iso_3166-1.mo"),
  translations("/usr/share/locale/fr/LC_MESSAGES/iso_3166-2.mo"),
]);

const countries = {};
for (const country of countryDocument["3166-1"] ?? []) {
  const code = String(country.alpha_2 ?? "").trim().toUpperCase();
  const english = String(country.name ?? "").trim();
  if (!/^[A-Z]{2}$/.test(code) || !english) continue;
  countries[code] = {
    alpha_3: String(country.alpha_3 ?? "").trim().toUpperCase(),
    numeric: String(country.numeric ?? "").trim(),
    names: {
      en: english,
      fr: frenchCountries.get(english) ?? english,
    },
  };
}

const subdivisions = {};
for (const subdivision of subdivisionDocument["3166-2"] ?? []) {
  const fullCode = String(subdivision.code ?? "").trim().toUpperCase();
  const separator = fullCode.indexOf("-");
  if (separator !== 2 || !countries[fullCode.slice(0, 2)]) continue;
  const country = fullCode.slice(0, 2);
  const code = fullCode.slice(separator + 1);
  const english = String(subdivision.name ?? "").trim();
  if (!code || !english) continue;
  subdivisions[country] ??= [];
  subdivisions[country].push({
    code,
    full_code: fullCode,
    type: String(subdivision.type ?? "").trim(),
    names: {
      en: english,
      fr: frenchSubdivisions.get(english) ?? english,
    },
  });
}

for (const entries of Object.values(subdivisions)) {
  entries.sort((left, right) => left.code.localeCompare(right.code, "en"));
}

const catalog = {
  schema_version: 1,
  source: "ISO 3166-1 and ISO 3166-2 via Debian iso-codes",
  countries: Object.fromEntries(Object.entries(countries).sort(([left], [right]) => left.localeCompare(right, "en"))),
  subdivisions: Object.fromEntries(Object.entries(subdivisions).sort(([left], [right]) => left.localeCompare(right, "en"))),
};

await writeFile(outputPath, `${JSON.stringify(catalog)}\n`, "utf8");
