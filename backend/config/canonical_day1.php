<?php

/*
| M2.12E minimal-safe Production Day-1 configuration.
|
| The UUIDs in this file are Production-local UUIDv4 identities. DEV is used
| only as a semantic oracle through stable business keys; no DEV UUID is a
| write source.
*/
return [
    'version' => 'm2.12e-minimal-safe-day1-v1',
    'expected_create_count' => 16,
    'production' => [
        'database' => 'u777904340_a3production',
        'master_user_id' => 'c83e9f52-9a34-49eb-b99a-de8dcb7b7431',
        'account' => ['id' => '4b26a0ee-e06f-4563-a6cc-9dfc7fbc0e0c', 'code' => 'CG', 'name' => 'Cipta Grafika', 'status' => 'active'],
        'tuparev' => ['id' => '94051ab9-235c-455f-b7ce-63f255cda3f6', 'code' => 'CG-TUP', 'name' => 'Tuparev'],
        'machine_model_id' => 'f3b91ad2-7ecc-4d0c-a6ce-648c08691dab',
        'machine' => ['id' => '708e199e-7f77-4219-b278-37d0b94821d4', 'code' => 'CG-TUP-A3-01'],
    ],
    'classifications' => [
        'GRAHA_BRANCH_MASTER' => 'APPROVED_CREATE',
        'GRAHA_MACHINE_CONFIGURATION' => 'DEFER',
        'GRAHA_PEOPLE' => 'DEFER',
        'GRAHA_AUTH' => 'DEFER',
        'TUPAREV_UNRESOLVED_PERSON_MAPPINGS' => 'DEFER',
        'PRODUCTION_PEOPLE_EXISTING' => 'KEEP',
        'PRODUCTION_CAPABILITIES' => 'KEEP',
        'SUPPLIER_JFP_RENAME' => 'DEFER',
    ],
    'creates' => [
        'branch' => [
            'id' => 'f5c2c443-63a1-44ae-aec9-a683106572ad',
            'account_id' => '4b26a0ee-e06f-4563-a6cc-9dfc7fbc0e0c',
            'code' => 'CG-GRH', 'name' => 'Graha', 'timezone' => 'Asia/Jakarta',
            'is_active' => true, 'archived_at' => null,
            'semantic_source' => 'approved Graha branch business key',
        ],
        'operational_policy' => [
            'account_id' => '4b26a0ee-e06f-4563-a6cc-9dfc7fbc0e0c',
            'operator_can_initialize_component' => false,
            'operator_can_replace_component' => true,
            'operator_can_create_purchase' => false,
            'operator_can_receive_goods' => false,
            'operator_can_adjust_inventory' => false,
            'operator_can_transfer_inventory' => false,
            'operator_can_log_errors' => true,
            'semantic_source' => 'canonical Cipta Grafika operational policy',
        ],
        'counter_types' => [
            ['id' => '5a59fc25-0a54-4e38-897a-5919a99e7793', 'code' => 'color_impressions', 'name' => 'Color Impressions', 'unit' => 'impressions', 'decimal_scale' => 0, 'is_monotonic' => true, 'is_active' => true],
            ['id' => 'b103151d-395a-4a41-9457-0a309adc606d', 'code' => 'bw_impressions', 'name' => 'Black and White Impressions', 'unit' => 'impressions', 'decimal_scale' => 0, 'is_monotonic' => true, 'is_active' => true],
            ['id' => '8793e5d6-1c00-48e5-a03b-0616fc07c444', 'code' => 'operating_hours', 'name' => 'Operating Hours', 'unit' => 'hours', 'decimal_scale' => 2, 'is_monotonic' => true, 'is_active' => true],
        ],
        'inventory_location' => [
            'id' => 'ea0f2d02-b1cf-4ed0-90e9-f00f630ca6a7',
            'account_id' => '4b26a0ee-e06f-4563-a6cc-9dfc7fbc0e0c',
            'branch_id' => '94051ab9-235c-455f-b7ce-63f255cda3f6',
            'code' => 'CG_DIGITAL', 'name' => 'CG Digital Print', 'is_active' => true, 'archived_at' => null,
            'semantic_source' => 'canonical Tuparev location business key',
        ],
        // These ten active C1070 components are the exact gap after the 18
        // component-linked legacy acquisition items already preserved in Production.
        'inventory_items' => [
            ['id' => 'c3da3893-e5b2-4365-a76d-b7b7672e26eb', 'component_id' => '56ace062-3dcd-5e07-889b-89b0868cae53', 'component_code' => 'CHARGING_CORONA_K', 'sku' => 'CAN-CHARGING-CORONA-K', 'name' => 'Charging Corona Black', 'category' => 'Imaging'],
            ['id' => '8ad0066d-e817-4784-8622-ebc3ec924458', 'component_id' => '926595e1-47a5-5d3e-b2d9-5596f2545865', 'component_code' => 'CHARGING_CORONA_Y', 'sku' => 'CAN-CHARGING-CORONA-Y', 'name' => 'Charging Corona Yellow', 'category' => 'Imaging'],
            ['id' => 'a4b6325f-0f51-4337-a2c6-a6585f46ba79', 'component_id' => 'fb781d10-544f-5dc4-a259-319f42b72af9', 'component_code' => 'CLEANING_UNIT', 'sku' => 'CAN-CLEANING-UNIT', 'name' => 'Cleaning Unit', 'category' => 'Cleaning'],
            ['id' => '5ec61b53-8808-4eef-9d63-70e2ecd2368e', 'component_id' => '86932e5f-c201-537d-bf42-5ac3ad3720b4', 'component_code' => 'DEVELOPER_M', 'sku' => 'CAN-DEVELOPER-M', 'name' => 'Developer Magenta', 'category' => 'Imaging'],
            ['id' => '362167d0-3dcd-4559-9ea4-73488ec155f1', 'component_id' => 'f5e8835f-037b-5dcc-a6a9-50a28f665ad9', 'component_code' => 'DEVELOPING_UNIT_C', 'sku' => 'CAN-DEVELOPING-UNIT-C', 'name' => 'Developing Unit Cyan', 'category' => 'Imaging'],
            ['id' => '14aff67b-67b3-4c5d-bc5f-419a1b8bbe95', 'component_id' => '43cdc9fc-c866-5fcd-afaf-6c7cbe84226f', 'component_code' => 'DEVELOPING_UNIT_K', 'sku' => 'CAN-DEVELOPING-UNIT-K', 'name' => 'Developing Unit Black', 'category' => 'Imaging'],
            ['id' => 'bd9023e5-1c08-4b1d-8fe4-cc3531ad3034', 'component_id' => 'dd7c717a-d22f-5172-b5f7-d68eb46db215', 'component_code' => 'DEVELOPING_UNIT_M', 'sku' => 'CAN-DEVELOPING-UNIT-M', 'name' => 'Developing Unit Magenta', 'category' => 'Imaging'],
            ['id' => '3e5eb43e-503f-454d-a3d6-d4ac10503a47', 'component_id' => '168ed0f5-9d00-5a64-8a3e-aca1a188af0b', 'component_code' => 'DRUM_K', 'sku' => 'CAN-DRUM-K', 'name' => 'Drum Unit Black', 'category' => 'Imaging'],
            ['id' => '3997477c-0b9d-4f70-905d-b066b0944f88', 'component_id' => '0848d74d-6e0d-556a-a4cb-5cd04f928266', 'component_code' => 'DRUM_Y', 'sku' => 'CAN-DRUM-Y', 'name' => 'Drum Unit Yellow', 'category' => 'Imaging'],
            ['id' => 'df52e18f-3a31-43e9-a537-3e14fcb7e96c', 'component_id' => '3e46c520-0c1b-540b-a3d1-09b27bb24617', 'component_code' => 'ROLL_MESIN', 'sku' => 'CAN-ROLL-MESIN', 'name' => 'Roll Mesin', 'category' => 'Paper Path'],
        ],
    ],
    // The DEV identities for the same concepts are denylisted explicitly.
    'prohibited_dev_uuids' => [
        '357e420a-c9ea-4404-9da4-f254c5dce5ef', '76d3c7ab-55c3-40f7-b133-0ef54a448893',
        'b4ca07ee-c588-404d-abcf-b6a029e68776', '51000000-0000-0000-0000-000000000001',
        '9f753339-0d54-42c9-9bb6-afe2461803f8',
        '52000000-0000-0000-0000-000000000002', '52000000-0000-0000-0000-000000000003', '52000000-0000-0000-0000-000000000004',
        'b6296488-5479-4dd0-9463-091891b4cbe4',
        '770eeac4-f202-4432-b54e-926746ddb34f', '00a04737-7eac-4ab1-a7f7-fd13ffc1a4b1', '9998747a-053b-4eae-a965-bf5fe26551aa',
        'f195c35f-ecca-4a85-abbc-6f945d5b1e0b', 'bd861c98-7a88-418d-b9df-2058266b44f9', '9625ec11-24ac-4422-8c12-e5e9481d917c',
        '390ff39b-04c1-4dfd-a871-9847692d1f4b', '7f33644d-e853-49eb-8b00-e36242e2a07b', 'e4cdad96-0168-43d3-91f3-7f4f09732fab',
        'c807e83a-7007-4bb4-8666-ef08e1d557c6',
        '53000000-0000-0000-0000-000000000003', '53000000-0000-0000-0000-000000000004',
        '53000000-0000-0000-0000-000000000006', '53000000-0000-0000-0000-000000000008',
        '53000000-0000-0000-0000-000000000011', '53000000-0000-0000-0000-000000000012',
        '53000000-0000-0000-0000-000000000014', '53000000-0000-0000-0000-000000000017',
        '53000000-0000-0000-0000-000000000018', '53000000-0000-0000-0000-000000000023',
    ],
];
