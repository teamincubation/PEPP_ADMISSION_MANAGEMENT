-- Database Migration 44: Study Plan 11 August Clone Remediation
-- Soft-deletes the 107 cloned August rows in Plan 11 (September 2026 PG) that were copied from Plan 5 during manual duplication.

UPDATE study_plan_activities
SET is_deleted = 1,
    deleted_at = NOW(),
    deleted_by = 'admin_remediation',
    deletion_reason = 'Contaminated August clone removed from September Plan 11'
WHERE study_plan_id = 11
  AND is_deleted = 0
  AND id IN (
      41247, 41248, 41249, 41250, 41251, 41252, 41253, 41254, 41255, 41256,
      41257, 41258, 41259, 41260, 41261, 41262, 41263, 41264, 41265, 41266,
      41267, 41268, 41269, 41270, 41271, 41272, 41273, 41274, 41275, 41276,
      41277, 41278, 41279, 41280, 41281, 41282, 41283, 41284, 41285, 41286,
      41287, 41288, 41289, 41290, 41291, 41292, 41293, 41294, 41295, 41296,
      41297, 41298, 41299, 41300, 41301, 41302, 41303, 41304, 41305, 41306,
      41307, 41308, 41309, 41310, 41311, 41312, 41313, 41314, 41315, 41316,
      41317, 41318, 41319, 41320, 41321, 41322, 41323, 41324, 41325, 41326,
      41327, 41328, 41329, 41330, 41331, 41332, 41333, 41334, 41335, 41336,
      41337, 41338, 41339, 41340, 41341, 41342, 41343, 41344, 41345, 41346,
      41347, 41348, 41349, 41350, 41351, 41352, 41353
  );
