import fs from "node:fs/promises";
import { SpreadsheetFile, Workbook } from "@oai/artifact-tool";

const workbook = Workbook.create();
const input = workbook.worksheets.add("รายการยา");
const guide = workbook.worksheets.add("คำแนะนำ");

input.showGridLines = false;
input.getRange("A1:F1").values = [["working_code", "name", "pack_size", "unit", "type", "fiscal_year"]];
input.getRange("A1:F101").format.font = { name: "Arial", size: 10, color: "#172033" };
input.getRange("A1:F1").format = {
  fill: "#0F766E",
  font: { name: "Arial", size: 10, bold: true, color: "#FFFFFF" },
  horizontalAlignment: "center",
  verticalAlignment: "center",
  borders: { preset: "all", style: "thin", color: "#D1FAE5" },
};
input.getRange("A2:F101").format = {
  fill: "#FFFBEB",
  font: { name: "Arial", size: 10, color: "#172033" },
  verticalAlignment: "center",
  borders: { preset: "inside", style: "thin", color: "#E2E8F0" },
};
input.getRange("A2:A101").format.numberFormat = "@";
input.getRange("B2:B101").format.numberFormat = "@";
input.getRange("D2:E101").format.numberFormat = "@";
input.getRange("C2:C101").format.numberFormat = "0.####";
input.getRange("F2:F101").format.numberFormat = "0";
input.getRange("C2:C101").dataValidation = { rule: { type: "decimal", operator: "greaterThan", formula1: 0 } };
input.getRange("F2:F101").dataValidation = { rule: { type: "whole", operator: "between", formula1: 2500, formula2: 3000 } };
input.getRange("A1:A101").format.columnWidth = 19;
input.getRange("B1:B101").format.columnWidth = 48;
input.getRange("C1:C101").format.columnWidth = 16;
input.getRange("D1:D101").format.columnWidth = 15;
input.getRange("E1:E101").format.columnWidth = 22;
input.getRange("F1:F101").format.columnWidth = 16;
input.getRange("A1:F1").format.rowHeight = 28;
input.freezePanes.freezeRows(1);

guide.showGridLines = false;
guide.getRange("A2:F2").values = [["ตัวอย่างการกรอกไฟล์นำเข้ารายการยา ปีงบประมาณ 2570", null, null, null, null, null]];
guide.getRange("A2:F2").merge();
guide.getRange("A4:F4").values = [["working_code", "name", "pack_size", "unit", "type", "fiscal_year"]];
guide.getRange("A5:F7").values = [
  ["NEW-PARA500", "พาราเซตามอล 500 มก.", 10, "แผง", "ยาเม็ด", 2570],
  ["NEW-AMOX250", "อะม็อกซีซิลลิน 250 มก.", 10, "แผง", "ยาปฏิชีวนะ", 2570],
  ["NEW-IBU200", "ไอบูโพรเฟน 200 มก.", 10, "เม็ด", "ยาเม็ด", 2570],
];
guide.getRange("A9:A13").values = [["วิธีใช้"], ["1. กรอกข้อมูลในชีต รายการยา โดยห้ามเปลี่ยนชื่อหัวคอลัมน์"], ["2. รหัสยาเป็นตัวตนหลัก ชื่อยาเหมือนรายการเดิมได้เมื่อรหัสต่างกัน"], ["3. ปีงบประมาณใช้ พ.ศ. 4 หลัก เช่น 2570"], ["4. บันทึกเป็นไฟล์ .xlsx แล้วนำเข้าผ่านหน้าจัดการรายการยา"]];
guide.getRange("A2:F13").format.font = { name: "Arial", size: 10, color: "#172033" };
guide.getRange("A2:F2").format = { font: { name: "Arial", size: 14, bold: true, color: "#0F766E" }, verticalAlignment: "center" };
guide.getRange("A4:F4").format = { fill: "#0F766E", font: { name: "Arial", size: 10, bold: true, color: "#FFFFFF" }, horizontalAlignment: "center", verticalAlignment: "center" };
guide.getRange("A5:F7").format.borders = { preset: "inside", style: "thin", color: "#CBD5E1" };
guide.getRange("A9").format.font = { name: "Arial", size: 11, bold: true, color: "#0F766E" };
guide.getRange("A1:A13").format.columnWidth = 19;
guide.getRange("B1:B13").format.columnWidth = 48;
guide.getRange("C1:C13").format.columnWidth = 16;
guide.getRange("D1:D13").format.columnWidth = 15;
guide.getRange("E1:E13").format.columnWidth = 22;
guide.getRange("F1:F13").format.columnWidth = 16;

workbook.recalculate();
const inspection = await workbook.inspect({ kind: "table", range: "คำแนะนำ!A2:F13", include: "values,formulas", tableMaxRows: 15, tableMaxCols: 8 });
console.log(inspection.ndjson);
const inputInspection = await workbook.inspect({ kind: "table", range: "รายการยา!A1:F8", include: "values,formulas", tableMaxRows: 10, tableMaxCols: 8 });
console.log(inputInspection.ndjson);
const errors = await workbook.inspect({ kind: "match", searchTerm: "#REF!|#DIV/0!|#VALUE!|#NAME\\?|#N/A|#NUM!|#NULL!|#SPILL!|#CALC!", options: { useRegex: true, maxResults: 50 }, summary: "formula error scan" });
console.log(errors.ndjson);
const preview = await workbook.render({ sheetName: "คำแนะนำ", range: "A1:F13", scale: 1.5, format: "png" });
await fs.writeFile("tmp/spreadsheet_build/drug_template_preview.png", new Uint8Array(await preview.arrayBuffer()));
const inputPreview = await workbook.render({ sheetName: "รายการยา", range: "A1:F12", scale: 1.5, format: "png" });
await fs.writeFile("tmp/spreadsheet_build/drug_template_input_preview.png", new Uint8Array(await inputPreview.arrayBuffer()));
const output = await SpreadsheetFile.exportXlsx(workbook);
await output.save("tool/drug_items_import_template.xlsx");
