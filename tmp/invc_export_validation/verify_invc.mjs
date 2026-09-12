import fs from "node:fs/promises";
import { FileBlob, SpreadsheetFile } from "@oai/artifact-tool";

const input = await FileBlob.load("tmp/invc_export_validation/INVC_test.xlsx");
const workbook = await SpreadsheetFile.importXlsx(input);
workbook.recalculate();

const table = await workbook.inspect({
  kind: "table",
  range: "INVC!A1:C8",
  include: "values,formulas",
  tableMaxRows: 8,
  tableMaxCols: 3,
});
const errors = await workbook.inspect({
  kind: "match",
  searchTerm: "#REF!|#DIV/0!|#VALUE!|#NAME\\?|#N/A|#NUM!|#NULL!|#SPILL!|#CALC!",
  options: { useRegex: true, maxResults: 50 },
  summary: "INVC formula error scan",
});
const preview = await workbook.render({
  sheetName: "INVC",
  range: "A1:C12",
  scale: 2,
  format: "png",
});
await fs.writeFile("tmp/invc_export_validation/INVC_preview.png", new Uint8Array(await preview.arrayBuffer()));

console.log(table.ndjson);
console.log(errors.ndjson);
