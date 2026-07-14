<?php
// includes/platform_rules.php — shared prohibited-item / hard-rule copy for FLIXO

if (!defined('PLATFORM_RULES_LOADED')) {
    define('PLATFORM_RULES_LOADED', true);
}

/**
 * Hard rules shown publicly (landing, sell form, terms).
 * Keep wording actionable and user-facing — not legal padding.
 *
 * @return list<array{icon:string,title:string,desc:string}>
 */
function flixo_hard_rules(): array {
    return [
        [
            'icon' => 'gavel',
            'title' => 'ห้ามสินค้าผิดกฎหมาย',
            'desc' => 'ยาเสพติด อาวุธของผิดกฎหมาย สินค้าที่กฎหมายไทยห้ามครอบครองหรือจำหน่าย',
        ],
        [
            'icon' => 'copyright',
            'title' => 'ห้ามสินค้าละเมิดลิขสิทธิ์',
            'desc' => 'สินค้าปลอม ซอฟต์แวร์เถื่อน มีเดียที่ละเมิดลิขสิทธิ์ หรือของที่มีตราเครื่องหมายโดยไม่ได้รับอนุญาต',
        ],
        [
            'icon' => 'medical_services',
            'title' => 'ห้ามสินค้าควบคุม',
            'desc' => 'ยาและเวชภัณฑ์ที่ต้องใบอนุญาต ของอันตราย สารเคมีอันตราย และสินค้าที่ต้องขออนุญาตก่อนจำหน่าย',
        ],
        [
            'icon' => 'warning',
            'title' => 'ห้ามหลอกลวงและนอกระบบ',
            'desc' => 'ห้ามโอนเงินนอก Escrow บัญชีปลอม ข้อมูลเท็จ หรือชี้นำติดต่อนอกแพลตฟอร์มเพื่อหลีกเลี่ยงการควบคุม',
        ],
    ];
}

/**
 * Detailed prohibited categories for terms of service.
 *
 * @return list<string>
 */
function flixo_prohibited_item_examples(): array {
    return [
        'ยาเสพติด วัตถุเสพติด และอุปกรณ์ที่เกี่ยวข้อง',
        'อาวุธ กระสุน วัตถุระเบิด และของผิดกฎหมายตามกฎหมายไทย',
        'สินค้าละเมิดลิขสิทธิ์ ทรัพย์สินทางปัญญา หรือของปลอมเลียนแบบ',
        'ซอฟต์แวร์ เกม บัญชีดิจิทัล หรือสื่อที่ละเมิดสิทธิ์โดยไม่ได้รับอนุญาต',
        'ยา เวชภัณฑ์ หรืออุปกรณ์การแพทย์ที่กฎหมายควบคุม/ต้องมีใบอนุญาต',
        'สารเคมีอันตราย ของเสียพิษ หรือสินค้าที่อาจก่ออันตรายต่อสาธารณะ',
        'เอกสารปลอม บัตรประชาชนปลอม หรือเอกสารราชการที่ผิดกฎหมาย',
        'สินค้าที่มาจากการฉ้อโกง ลักทรัพย์ หรือไม่มีสิทธิ์ในทรัพย์สินที่ชัดเจน',
    ];
}
